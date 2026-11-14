<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Platform-wide settings that staff change in Platform Control: plan limits and prices, which modules
 * are switched on, which AI model each feature uses, and general switches. Defaults come from
 * config/findriva.php; saved changes sit on top. Secrets never live here.
 */
class PlatformConfig
{
    public const LIMITS = [
        'businesses' => 'Businesses per workspace',
        'locations_per_business' => 'Locations per business',
        'pages_per_crawl' => 'Pages checked per website audit',
        'crawls_per_month' => 'Website audits per month',
        'content_generations_per_day' => 'Content drafts per day',
        'keyword_spend_usd_per_day' => 'Keyword data spend per day (USD)',
        'rank_tracked_keywords' => 'Tracked keywords',
        'rank_spend_usd_per_day' => 'Rank check spend per day (USD)',
        'ai_answer_questions' => 'AI answer questions',
        'ai_answer_spend_usd_per_day' => 'AI answer spend per day (USD)',
        'local_grid_points_per_month' => 'Map grid points per month',
    ];

    public const MODULES = [
        'keywords' => ['Keywords', 'Keyword ideas and paid search-volume lookups'],
        'rankings' => ['Rankings & competitors', 'Live position checks, including the weekly run'],
        'map_grid' => ['Map grid', 'Google Maps grid scans'],
        'ai_answers' => ['AI answers', 'Questions asked to an AI assistant, including the weekly run'],
        'content_studio' => ['Content Studio', 'Drafting posts and descriptions'],
        'public_pages' => ['Public pages', 'Hosted business pages on the Findriva domain'],
        'wordpress' => ['Website connection', 'The Findriva Connect plugin for WordPress'],
        'google_profile' => ['Google profile & reviews', 'Business Profile import and review replies'],
        'bing' => ['Bing', 'Bing Webmaster Tools import'],
    ];

    /** Models staff may pick, with list prices (USD per million tokens) used to record what each call cost. */
    public const MODELS = [
        'claude-opus-5-5' => ['label' => 'Claude Opus 5.5 (most capable)', 'input' => 4.0, 'output' => 20.0],
        'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5 (balanced, cheaper)', 'input' => 2.0, 'output' => 10.0],
    ];

    private const CACHE = 'platform-settings';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> */
    private function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE, fn () => Schema::hasTable('platform_settings')
                ? PlatformSetting::query()->pluck('value', 'key')->all()
                : []);
        } catch (Throwable) {
            return [];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->stored()[$key] ?? $default;
    }

    public function put(string $key, array $value, ?User $by): void
    {
        $before = $this->get($key);
        PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);
        $this->audit->log('platform.settings_updated', null, ['key' => $key, 'value' => $before], ['key' => $key, 'value' => $value]);
    }

    /** @return array<string, array{label: string, price_kobo: int|null, limits: array<string, int|float>, defaults: array<string, mixed>}> */
    public function plans(): array
    {
        $over = (array) $this->get('plans', []);
        $prices = (array) config('findriva.billing.prices');
        $out = [];
        foreach ((array) config('findriva.plans') as $key => $cfg) {
            $o = (array) ($over[$key] ?? []);
            $limits = [];
            foreach (array_keys(self::LIMITS) as $l) {
                $limits[$l] = $o['limits'][$l] ?? $cfg[$l] ?? 0;
            }
            $out[$key] = [
                'label' => (string) ($o['label'] ?? $cfg['label']),
                'price_kobo' => $key === 'free' ? null : (int) ($o['price_kobo'] ?? $prices[$key] ?? 0),
                'limits' => $limits,
                'defaults' => ['label' => $cfg['label'], 'price_kobo' => $key === 'free' ? null : (int) ($prices[$key] ?? 0)]
                    + array_intersect_key($cfg, self::LIMITS),
            ];
        }

        return $out;
    }

    /** The shape the rest of the app has always used: label plus each limit. */
    public function entitlements(string $plan): array
    {
        $plans = $this->plans();
        $p = $plans[$plan] ?? $plans['free'];

        return ['label' => $p['label']] + $p['limits'];
    }

    /** @return array<string, int> monthly price in kobo for each paid plan */
    public function prices(): array
    {
        return collect($this->plans())->filter(fn ($p) => $p['price_kobo'] !== null)->map(fn ($p) => $p['price_kobo'])->all();
    }

    /** @return array<string, bool> */
    public function modules(): array
    {
        $saved = (array) $this->get('modules', []);

        return collect(self::MODULES)->mapWithKeys(fn ($_, $k) => [$k => (bool) ($saved[$k] ?? true)])->all();
    }

    public function moduleOn(string $module): bool
    {
        return $this->modules()[$module] ?? true;
    }

    /** @return array{answers_enabled: bool, answers_model: string, content_engine: string, content_model: string} */
    public function ai(): array
    {
        $saved = (array) $this->get('ai', []);
        $model = fn ($m) => array_key_exists((string) $m, self::MODELS) ? (string) $m : 'claude-opus-5-5';

        return [
            'answers_enabled' => (bool) ($saved['answers_enabled'] ?? config('findriva.ai_answers.engine') === 'claude'),
            'answers_model' => $model($saved['answers_model'] ?? config('findriva.ai_answers.model')),
            'content_engine' => in_array($saved['content_engine'] ?? null, ['template', 'claude'], true) ? $saved['content_engine'] : (config('findriva.content.generator') === 'claude' ? 'claude' : 'template'),
            'content_model' => $model($saved['content_model'] ?? config('findriva.content.claude_model')),
        ];
    }

    /** @return array{signups_open: bool, announcement: string|null} */
    public function general(): array
    {
        $saved = (array) $this->get('general', []);

        return [
            'signups_open' => (bool) ($saved['signups_open'] ?? true),
            'announcement' => filled($saved['announcement'] ?? null) ? (string) $saved['announcement'] : null,
        ];
    }
}
