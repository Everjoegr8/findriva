<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\BillingTransaction;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    private array $verifyResponse = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_x', 'findriva.app_url' => 'http://app.test']);
        Http::fake(function (Request $r) {
            $this->sent[] = $r;
            if (str_ends_with($r->url(), '/transaction/initialize')) {
                return Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => $r['reference']]]);
            }
            if (str_contains($r->url(), '/transaction/verify/')) {
                return Http::response(['status' => true, 'data' => $this->verifyResponse]);
            }

            return Http::response(['status' => false], 404);
        });
    }

    private function webhook(array $payload, ?string $secret = 'sk_test_x')
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/v1/billing/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, (string) $secret),
        ], $body);
    }

    public function test_checkout_is_owner_only_and_plan_applies_only_after_paystack_confirms_the_exact_amount(): void
    {
        [$owner, $ws] = $this->member(plan: 'free');
        [$admin] = $this->member(WorkspaceRole::Admin, $ws);
        $this->as($admin, $ws)->postJson('/api/v1/workspace/billing/checkout', ['plan' => 'growth'])->assertForbidden();

        $res = $this->as($owner, $ws)->postJson('/api/v1/workspace/billing/checkout', ['plan' => 'growth'])->assertOk()->json('data');
        $this->assertSame('https://checkout.paystack.com/abc', $res['authorization_url']);
        $init = $this->sent[0];
        $this->assertSame(2990000, $init['amount']);
        $this->assertSame('NGN', $init['currency']);
        $this->assertSame('Bearer sk_test_x', $init->header('Authorization')[0]);
        $this->assertSame('free', $ws->fresh()->plan, 'Nothing changes before payment is confirmed.');

        // Paystack says success but for less money: ignored.
        $this->verifyResponse = ['status' => 'success', 'amount' => 100, 'currency' => 'NGN', 'reference' => $res['reference']];
        $this->as($owner, $ws)->postJson('/api/v1/workspace/billing/confirm', ['reference' => $res['reference']])->assertJsonPath('data.plan', 'free');

        $this->verifyResponse = ['status' => 'success', 'amount' => 2990000, 'currency' => 'NGN', 'reference' => $res['reference'], 'id' => 77, 'customer' => ['customer_code' => 'CUS_1']];
        $this->as($owner, $ws)->postJson('/api/v1/workspace/billing/confirm', ['reference' => $res['reference']])->assertOk()
            ->assertJsonPath('data.plan', 'growth')->assertJsonPath('data.status', 'success');
        $ws->refresh();
        $this->assertSame('active', $ws->billing_status);
        $this->assertTrue($ws->current_period_end->between(now()->addDays(27), now()->addDays(32)));

        $billing = $this->as($owner, $ws)->getJson('/api/v1/workspace/billing')->json('data');
        $this->assertSame(2990000, $billing['history'][0]['amount']);
    }

    public function test_webhook_requires_a_valid_signature_and_is_processed_once(): void
    {
        [$owner, $ws] = $this->member(plan: 'free');
        $tx = BillingTransaction::create(['workspace_id' => $ws->id, 'provider' => 'paystack', 'reference' => 'fdv_ref_1', 'plan' => 'starter', 'amount' => 990000, 'currency' => 'NGN']);
        $payload = ['event' => 'charge.success', 'data' => ['id' => 5, 'status' => 'success', 'reference' => 'fdv_ref_1', 'amount' => 990000, 'currency' => 'NGN', 'customer' => ['customer_code' => 'CUS_9']]];

        $this->webhook($payload, 'wrong-secret')->assertUnauthorized();
        $this->assertSame('free', $ws->fresh()->plan);

        $this->webhook($payload)->assertOk();
        $ws->refresh();
        $this->assertSame('starter', $ws->plan);
        $end = $ws->current_period_end;

        $this->webhook($payload)->assertOk(); // replay
        $this->assertTrue($ws->fresh()->current_period_end->equalTo($end), 'A replayed webhook must not add another month.');
        $this->assertSame('success', $tx->fresh()->status);

        // Renewal from the subscription (no pending row on our side) extends the period.
        $this->webhook(['event' => 'charge.success', 'data' => ['id' => 6, 'status' => 'success', 'reference' => 'paystack_auto', 'amount' => 990000, 'currency' => 'NGN', 'customer' => ['customer_code' => 'CUS_9']]])->assertOk();
        $this->assertTrue($ws->fresh()->current_period_end->greaterThan($end));

        $this->webhook(['event' => 'subscription.not_renew', 'data' => ['subscription_code' => 'SUB_1', 'customer' => ['customer_code' => 'CUS_9']]])->assertOk();
        $this->assertTrue($ws->fresh()->cancel_at_period_end);
    }

    public function test_lapsed_paid_plans_return_to_free_after_the_grace_period(): void
    {
        [, $ws] = $this->member();
        $ws->update(['plan' => 'growth', 'billing_status' => 'active', 'billing_provider' => 'paystack', 'current_period_end' => now()->subDays(2)]);
        [, $manual] = $this->member(); // plan set by Findriva staff, not by Paystack: untouched
        $manual->update(['plan' => 'agency', 'current_period_end' => now()->subDays(30)]);

        $service = app(BillingService::class);
        $this->assertSame(0, $service->expireLapsed());
        $this->travel(2)->days();
        $this->assertSame(1, $service->expireLapsed());
        $this->assertSame('free', $ws->fresh()->plan);
        $this->assertSame('agency', $manual->fresh()->plan);
    }

    public function test_checkout_explains_when_payments_are_not_set_up(): void
    {
        config(['services.paystack.secret_key' => null]);
        [$owner, $ws] = $this->member();
        $this->as($owner, $ws)->postJson('/api/v1/workspace/billing/checkout', ['plan' => 'starter'])->assertUnprocessable()->assertJsonValidationErrors('plan');
        $this->as($owner, $ws)->getJson('/api/v1/workspace/billing')->assertJsonPath('data.online_payment', false);
        $this->webhook(['event' => 'charge.success'])->assertUnauthorized();
    }
}
