<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Services\Billing\BillingService;
use App\Services\Billing\Paystack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly Paystack $paystack) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $ws = $this->workspace();
        $plans = collect(config('findriva.plans'))->map(fn ($p, $key) => [
            'key' => $key, 'label' => $p['label'], 'price' => $this->billing->prices()[$key] ?? 0,
            'businesses' => $p['businesses'], 'locations_per_business' => $p['locations_per_business'],
            'pages_per_crawl' => $p['pages_per_crawl'], 'crawls_per_month' => $p['crawls_per_month'],
            'content_generations_per_day' => $p['content_generations_per_day'], 'keyword_spend_usd_per_day' => $p['keyword_spend_usd_per_day'],
        ])->values();

        return response()->json(['data' => [
            'plan' => $ws->plan, 'billing_status' => $ws->billing_status, 'current_period_end' => $ws->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $ws->cancel_at_period_end, 'currency' => config('findriva.billing.currency'),
            'plans' => $plans, 'online_payment' => $this->paystack->isConfigured(),
            'can_manage' => (bool) $request->user()->canIn($ws, Permission::BillingManage),
            'history' => BillingTransaction::query()->where('workspace_id', $ws->id)->where('status', 'success')->latest('id')->limit(12)
                ->get(['reference', 'plan', 'amount', 'currency', 'paid_at']),
        ]]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $this->authorizePermission(Permission::BillingManage);
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(config('findriva.billing.prices')))]]);

        return response()->json(['data' => $this->billing->checkout($this->workspace(), $request->user(), $data['plan'])]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);
        $tx = $this->billing->confirm($this->workspace(), $data['reference']);
        $ws = $this->workspace()->fresh();

        return response()->json(['data' => ['status' => $tx->status, 'plan' => $ws->plan, 'current_period_end' => $ws->current_period_end?->toIso8601String()]]);
    }

    /** Paystack calls this. The signature over the raw body is the only thing we trust. */
    public function webhook(Request $request): JsonResponse
    {
        if (! $this->paystack->validSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $this->billing->handleWebhook((array) json_decode($request->getContent(), true));

        return response()->json(['received' => true]);
    }
}
