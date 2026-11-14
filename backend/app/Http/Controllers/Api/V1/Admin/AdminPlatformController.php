<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Models\IntegrationConnection;
use App\Models\SiteConnector;
use App\Models\UsageEvent;
use App\Services\Platform\PlatformConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform Control configuration: plans and limits, modules, providers, AI models and general switches. */
class AdminPlatformController extends Controller
{
    public function __construct(private readonly PlatformConfig $platform) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'plans' => $this->platform->plans(),
            'limit_labels' => PlatformConfig::LIMITS,
            'paystack_plan_codes' => collect((array) config('findriva.billing.paystack_plans'))->map(fn ($c) => filled($c)),
            'modules' => collect(PlatformConfig::MODULES)->map(fn ($m, $k) => ['key' => $k, 'label' => $m[0], 'description' => $m[1], 'enabled' => $this->platform->moduleOn($k)])->values(),
            'ai' => $this->platform->ai() + [
                'key_configured' => filled(config('services.anthropic.key')),
                'models' => collect(PlatformConfig::MODELS)->map(fn ($m, $k) => ['id' => $k] + $m)->values(),
                'answer_spend_30d_usd' => round((float) UsageEvent::withoutGlobalScopes()->where('action', 'ai_answer_check')->where('created_at', '>=', now()->subDays(30))->sum('estimated_cost'), 2),
            ],
            'general' => $this->platform->general(),
            'providers' => $this->providers(),
        ]]);
    }

    public function updatePlan(Request $request, string $plan): JsonResponse
    {
        abort_unless(array_key_exists($plan, (array) config('findriva.plans')), 404);
        $rules = ['label' => ['required', 'string', 'max:40'], 'limits' => ['required', 'array']];
        foreach (array_keys(PlatformConfig::LIMITS) as $l) {
            $rules["limits.$l"] = str_contains($l, '_usd_') ? ['required', 'numeric', 'min:0', 'max:1000'] : ['required', 'integer', 'min:0', 'max:100000'];
        }
        if ($plan !== 'free') {
            // Whole naira only (kobo multiples of 100), between ₦100 and ₦10,000,000.
            $rules['price_kobo'] = ['required', 'integer', 'min:10000', 'max:1000000000', 'multiple_of:100'];
        }
        $data = $request->validate($rules);
        $limits = [];
        foreach (array_keys(PlatformConfig::LIMITS) as $l) {
            $limits[$l] = str_contains($l, '_usd_') ? round((float) $data['limits'][$l], 2) : (int) $data['limits'][$l];
        }
        $all = (array) $this->platform->get('plans', []);
        $all[$plan] = array_filter(['label' => $data['label'], 'limits' => $limits, 'price_kobo' => $data['price_kobo'] ?? null], fn ($v) => $v !== null);
        $this->platform->put('plans', $all, $request->user());

        return response()->json(['data' => $this->platform->plans()[$plan]]);
    }

    public function updateModules(Request $request): JsonResponse
    {
        $rules = [];
        foreach (array_keys(PlatformConfig::MODULES) as $k) {
            $rules[$k] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $this->platform->put('modules', array_merge($this->platform->modules(), array_map('boolval', $data)), $request->user());

        return response()->json(['data' => $this->platform->modules()]);
    }

    public function updateAi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'answers_enabled' => ['required', 'boolean'],
            'answers_model' => ['required', Rule::in(array_keys(PlatformConfig::MODELS))],
            'content_engine' => ['required', Rule::in(['template', 'claude'])],
            'content_model' => ['required', Rule::in(array_keys(PlatformConfig::MODELS))],
        ]);
        $this->platform->put('ai', $data, $request->user());

        return response()->json(['data' => $this->platform->ai()]);
    }

    public function updateGeneral(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signups_open' => ['required', 'boolean'],
            'announcement' => ['nullable', 'string', 'max:280'],
        ]);
        $this->platform->put('general', ['signups_open' => (bool) $data['signups_open'], 'announcement' => filled($data['announcement'] ?? null) ? trim($data['announcement']) : null], $request->user());

        return response()->json(['data' => $this->platform->general()]);
    }

    /** Each outside service: whether its keys are set (never the keys) and what the database shows about it. */
    private function providers(): array
    {
        $connections = IntegrationConnection::withoutGlobalScopes()->selectRaw('provider, status, COUNT(*) as n')->groupBy('provider', 'status')->get()
            ->groupBy('provider')->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n));
        $conn = fn (string $p) => $connections->get($p, collect())->all();
        $lastPayment = BillingTransaction::query()->where('status', 'success')->max('paid_at');
        $keywordProvider = config('findriva.keywords.provider');

        return [
            [
                'key' => 'paystack', 'name' => 'Paystack', 'powers' => 'Plan payments and renewals',
                'configured' => filled(config('services.paystack.secret_key')), 'env' => ['PAYSTACK_SECRET_KEY', 'PAYSTACK_PUBLIC_KEY'],
                'facts' => ['Last successful payment' => $lastPayment ? (string) $lastPayment : 'None yet'],
            ],
            [
                'key' => 'google', 'name' => 'Google (Search Console and Business Profile)', 'powers' => 'Search results import, Maps profile and reviews',
                'configured' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')), 'env' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'],
                'connections' => ['Search Console' => $conn('google_search_console'), 'Business Profile' => $conn('google_business_profile')],
            ],
            [
                'key' => 'bing', 'name' => 'Bing Webmaster Tools', 'powers' => 'Bing search results import',
                'configured' => filled(config('services.bing_webmaster.client_id')) && filled(config('services.bing_webmaster.client_secret')), 'env' => ['BING_WEBMASTER_CLIENT_ID', 'BING_WEBMASTER_CLIENT_SECRET'],
                'connections' => ['Bing Webmaster' => $conn('bing_webmaster')],
            ],
            [
                'key' => 'dataforseo', 'name' => 'DataForSEO', 'powers' => 'Search volumes, live rankings and map grid scans',
                'configured' => $keywordProvider === 'dataforseo' && filled(config('findriva.keywords.dataforseo.login')), 'env' => ['FINDRIVA_KEYWORD_PROVIDER=dataforseo', 'DATAFORSEO_LOGIN', 'DATAFORSEO_PASSWORD'],
                'facts' => ['Rank and map provider' => (string) (config('findriva.rankings.provider') ?: 'Not set')],
            ],
            [
                'key' => 'anthropic', 'name' => 'Anthropic (Claude)', 'powers' => 'AI answer checks and optional AI drafting',
                'configured' => filled(config('services.anthropic.key')), 'env' => ['ANTHROPIC_API_KEY'],
            ],
            [
                'key' => 'wordpress', 'name' => 'Findriva Connect for WordPress', 'powers' => 'Publishing approved fixes to customer sites',
                'configured' => true, 'env' => [],
                'facts' => ['Connected sites' => (string) SiteConnector::withoutGlobalScopes()->where('status', 'connected')->count()],
            ],
            [
                'key' => 'indexnow', 'name' => 'IndexNow', 'powers' => 'Telling Bing and others when public pages change',
                'configured' => filled(config('findriva.indexnow.platform_key')), 'env' => ['FINDRIVA_INDEXNOW_KEY'],
            ],
            [
                'key' => 'mail', 'name' => 'Email', 'powers' => 'Confirmation and password reset emails',
                'configured' => ! in_array(config('mail.default'), ['log', 'array'], true), 'env' => ['MAIL_MAILER', 'RESEND_API_KEY'],
                'facts' => ['Mailer' => (string) config('mail.default')],
            ],
        ];
    }
}
