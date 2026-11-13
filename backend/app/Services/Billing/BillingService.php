<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\BillingTransaction;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Plan purchases through Paystack. A plan is only applied after Paystack confirms the payment
 * (by our own verify call or a signed webhook) for the exact amount and currency we asked for.
 */
class BillingService
{
    public function __construct(private readonly Paystack $paystack, private readonly AuditLogger $audit) {}

    public function prices(): array
    {
        return config('findriva.billing.prices');
    }

    public function checkout(Workspace $workspace, User $by, string $plan): array
    {
        if (! $this->paystack->isConfigured()) {
            throw ValidationException::withMessages(['plan' => 'Online payment is not set up on this Findriva installation yet.']);
        }
        $amount = $this->prices()[$plan] ?? null;
        if (! $amount) {
            throw ValidationException::withMessages(['plan' => 'Choose a paid plan.']);
        }
        $reference = 'fdv_'.$workspace->id.'_'.Str::lower(Str::random(16));
        $tx = BillingTransaction::create([
            'workspace_id' => $workspace->id, 'user_id' => $by->id, 'provider' => 'paystack', 'reference' => $reference,
            'plan' => $plan, 'amount' => $amount, 'currency' => config('findriva.billing.currency'), 'status' => 'pending',
        ]);
        $payload = array_filter([
            'email' => $by->email,
            'amount' => $amount,
            'currency' => $tx->currency,
            'reference' => $reference,
            'callback_url' => rtrim(config('findriva.app_url'), '/').'/billing?reference='.$reference,
            'plan' => config('findriva.billing.paystack_plans.'.$plan) ?: null,
            'metadata' => ['workspace_id' => $workspace->id, 'plan' => $plan, 'transaction_id' => $tx->id],
            'channels' => ['card', 'bank', 'ussd', 'bank_transfer'],
        ]);
        try {
            $data = $this->paystack->initialize($payload);
        } catch (RuntimeException $e) {
            $tx->update(['status' => 'failed', 'provider_data' => ['error' => $e->getMessage()]]);
            throw ValidationException::withMessages(['plan' => 'Paystack could not start the payment. Please try again.']);
        }
        $this->audit->log('billing.checkout_started', $workspace, null, ['plan' => $plan, 'amount' => $amount, 'reference' => $reference], $workspace->id);

        return ['authorization_url' => $data['authorization_url'], 'reference' => $reference];
    }

    /** Called when the customer returns from Paystack. Safe to call repeatedly. */
    public function confirm(Workspace $workspace, string $reference): BillingTransaction
    {
        $tx = BillingTransaction::query()->where('workspace_id', $workspace->id)->where('reference', $reference)->firstOrFail();
        if ($tx->status === 'success') {
            return $tx;
        }
        try {
            $data = $this->paystack->verify($reference);
        } catch (RuntimeException) {
            return $tx;
        }

        return $this->settle($tx, $data);
    }

    /** Handles a verified webhook. Returns false if the event was already processed. */
    public function handleWebhook(array $event): bool
    {
        $name = (string) ($event['event'] ?? '');
        $data = (array) ($event['data'] ?? []);
        $key = hash('sha256', $name.'|'.($data['id'] ?? '').'|'.($data['reference'] ?? '').'|'.($data['subscription_code'] ?? ''));

        return DB::transaction(function () use ($name, $data, $key, $event) {
            if (BillingEvent::query()->where('event_key', $key)->lockForUpdate()->exists()) {
                return false;
            }
            $workspaceId = null;
            switch ($name) {
                case 'charge.success':
                    $tx = BillingTransaction::query()->where('reference', $data['reference'] ?? '')->first();
                    if ($tx) {
                        $this->settle($tx, $data);
                        $workspaceId = $tx->workspace_id;
                    } elseif ($ws = $this->workspaceForCustomer($data)) {
                        // A subscription renewal: Paystack created the charge, so we have no pending row.
                        $plan = $this->planForCode($data['plan']['plan_code'] ?? null) ?? $ws->plan;
                        $expected = $this->prices()[$plan] ?? null;
                        if ($expected && (int) $data['amount'] === $expected && ($data['currency'] ?? '') === config('findriva.billing.currency')) {
                            $this->activate($ws, $plan, $data);
                        }
                        $workspaceId = $ws->id;
                    }
                    break;
                case 'subscription.create':
                    if ($ws = $this->workspaceForCustomer($data)) {
                        $ws->update(['billing_subscription_code' => $data['subscription_code'] ?? null, 'cancel_at_period_end' => false]);
                        $workspaceId = $ws->id;
                    }
                    break;
                case 'subscription.not_renew':
                case 'subscription.disable':
                    if ($ws = $this->workspaceForCustomer($data)) {
                        $ws->update(['cancel_at_period_end' => true]);
                        $this->audit->log('billing.cancelled', $ws, null, ['event' => $name], $ws->id);
                        $workspaceId = $ws->id;
                    }
                    break;
                case 'invoice.payment_failed':
                    if ($ws = $this->workspaceForCustomer($data)) {
                        $ws->update(['billing_status' => 'past_due']);
                        $this->audit->log('billing.payment_failed', $ws, null, null, $ws->id);
                        $workspaceId = $ws->id;
                    }
                    break;
            }
            BillingEvent::create(['provider' => 'paystack', 'event_key' => $key, 'event' => $name ?: 'unknown', 'workspace_id' => $workspaceId, 'payload' => $event]);

            return true;
        });
    }

    /** Downgrades paid workspaces whose period ended (after a short grace period). */
    public function expireLapsed(): int
    {
        $cutoff = now()->subDays((int) config('findriva.billing.grace_days'));
        $count = 0;
        Workspace::query()->where('billing_provider', 'paystack')->where('plan', '!=', 'free')
            ->whereNotNull('current_period_end')->where('current_period_end', '<', $cutoff)
            ->each(function (Workspace $ws) use (&$count) {
                $before = $ws->only(['plan', 'billing_status']);
                $ws->update(['plan' => 'free', 'billing_status' => 'expired', 'cancel_at_period_end' => false]);
                $this->audit->log('billing.expired', $ws, $before, ['plan' => 'free'], $ws->id);
                $count++;
            });

        return $count;
    }

    private function settle(BillingTransaction $tx, array $data): BillingTransaction
    {
        $paid = ($data['status'] ?? '') === 'success'
            && (int) ($data['amount'] ?? 0) === (int) $tx->amount
            && strtoupper((string) ($data['currency'] ?? '')) === $tx->currency;
        if (! $paid) {
            if (in_array($data['status'] ?? '', ['failed', 'abandoned'], true)) {
                $tx->update(['status' => $data['status']]);
            }

            return $tx;
        }
        if ($tx->status !== 'success') {
            $tx->update(['status' => 'success', 'paid_at' => now(), 'provider_data' => [
                'id' => $data['id'] ?? null, 'channel' => $data['channel'] ?? null, 'paid_at' => $data['paid_at'] ?? null,
            ]]);
            $this->activate($tx->workspace, $tx->plan, $data);
        }

        return $tx;
    }

    private function activate(Workspace $ws, string $plan, array $data): void
    {
        $before = $ws->only(['plan', 'billing_status', 'current_period_end']);
        $base = $ws->current_period_end && $ws->current_period_end->isFuture() && $ws->plan === $plan ? $ws->current_period_end : now();
        $ws->update([
            'plan' => $plan, 'billing_status' => 'active', 'billing_provider' => 'paystack',
            'billing_customer_code' => $data['customer']['customer_code'] ?? $ws->billing_customer_code,
            'current_period_end' => $base->copy()->addMonthNoOverflow(), 'cancel_at_period_end' => false,
        ]);
        $this->audit->log('billing.plan_activated', $ws, $before, ['plan' => $plan, 'current_period_end' => $ws->current_period_end?->toIso8601String()], $ws->id);
    }

    private function workspaceForCustomer(array $data): ?Workspace
    {
        $code = $data['customer']['customer_code'] ?? null;

        return $code ? Workspace::query()->where('billing_customer_code', $code)->first() : null;
    }

    private function planForCode(?string $code): ?string
    {
        return $code ? array_search($code, array_filter(config('findriva.billing.paystack_plans')), true) ?: null : null;
    }
}
