<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BillingTransaction;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $u = User::factory()->create(['name' => 'Ops Person']);
        $u->forceFill(['is_platform_admin' => true])->save();

        return $u;
    }

    public function test_admin_access_is_granted_only_from_the_server_command(): void
    {
        $u = User::factory()->create(['email' => 'owner@example.com']);
        $this->actingAs($u, 'sanctum')->getJson('/api/v1/admin/users')->assertForbidden();

        $this->artisan('findriva:admin', ['email' => 'nobody@example.com'])->assertFailed();
        $this->artisan('findriva:admin', ['email' => 'OWNER@example.com'])->assertSuccessful();
        $this->assertTrue($u->fresh()->is_platform_admin);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.granted')->exists());
        $this->actingAs($u->fresh(), 'sanctum')->getJson('/api/v1/admin/users')->assertOk();

        $this->artisan('findriva:admin', ['email' => 'owner@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($u->fresh()->is_platform_admin);
    }

    public function test_stats_users_and_workspace_detail_never_expose_secrets(): void
    {
        Queue::fake();
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://glow.example'])->json('data.id');
        IntegrationConnection::withoutGlobalScopes()->create(['workspace_id' => $ws->id, 'business_id' => $id, 'provider' => 'google_search_console', 'status' => 'connected', 'access_token' => 'ya29.secret-access', 'refresh_token' => 'secret-refresh']);
        $ws->update(['plan' => 'growth', 'billing_status' => 'active']);
        BillingTransaction::withoutGlobalScopes()->create(['workspace_id' => $ws->id, 'provider' => 'paystack', 'reference' => 'ref-1', 'plan' => 'growth', 'amount' => 2990000, 'currency' => 'NGN', 'status' => 'success', 'paid_at' => now()]);
        $owner->forceFill(['password' => 'a-long-password-123'])->save();

        $staff = $this->staff();
        $this->app['auth']->forgetGuards();
        $stats = $this->actingAs($staff, 'sanctum')->getJson('/api/v1/admin/stats')->assertOk();
        $stats->assertJsonPath('data.paying_workspaces', 1)->assertJsonPath('data.mrr', 2990000)->assertJsonPath('data.revenue_30d', 2990000);

        $this->getJson('/api/v1/admin/users?search='.urlencode($owner->email))->assertOk()->assertJsonPath('data.0.id', $owner->id)->assertJsonPath('total', 1);
        $user = $this->getJson("/api/v1/admin/users/{$owner->id}")->assertOk()->assertJsonPath('data.workspaces.0.role', 'owner');
        $detail = $this->getJson("/api/v1/admin/workspaces/{$ws->id}")->assertOk()
            ->assertJsonPath('data.businesses.0.name', 'Glow by Ada')
            ->assertJsonPath('data.businesses.0.connections.0.provider', 'google_search_console')
            ->assertJsonPath('data.payments.0.reference', 'ref-1');
        foreach ([$user->getContent(), $detail->getContent()] as $body) {
            $this->assertStringNotContainsString('secret', $body);
            $this->assertStringNotContainsString('password', $body);
            $this->assertStringNotContainsString('mfa_secret', $body);
        }
        $this->getJson('/api/v1/admin/workspaces?search='.urlencode($owner->email))->assertOk()->assertJsonPath('data.0.id', $ws->id);
        $this->getJson('/api/v1/admin/trends?days=7')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/v1/admin/payments')->assertOk()->assertJsonPath('data.0.workspace_name', $ws->name);
    }

    public function test_suspending_an_account_signs_it_out_and_blocks_sign_in(): void
    {
        [$owner] = $this->member();
        $owner->forceFill(['password' => 'a-long-password-123'])->save();
        $owner->createToken('web');
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/admin/users/{$owner->id}/disable", [])->assertStatus(422);
        $this->postJson("/api/v1/admin/users/{$staff->id}/disable", ['reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/users/{$owner->id}/disable", ['reason' => 'Chargeback fraud'])->assertOk()->assertJsonPath('data.disabled_reason', 'Chargeback fraud');
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.user_disabled')->where('actor_id', $staff->id)->exists());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'a-long-password-123'])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/admin/users/{$owner->id}/enable")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'a-long-password-123'])->assertOk();
        $this->assertNotNull($owner->fresh()->last_login_at);
    }

    public function test_support_can_send_links_but_never_sees_or_sets_a_password(): void
    {
        Notification::fake();
        [$owner] = $this->member();
        $owner->forceFill(['email_verified_at' => null])->save();
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/admin/users/{$owner->id}/password-reset")->assertOk();
        Notification::assertSentTo($owner, ResetPassword::class);
        $this->postJson("/api/v1/admin/users/{$owner->id}/resend-verification")->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'admin.password_reset_sent')->exists());
    }

    public function test_notes_failed_jobs_and_audit_rerun(): void
    {
        Queue::fake();
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Glow', 'primary_category' => 'Salon', 'website_url' => 'https://glow.example'])->json('data.id');
        $staff = $this->staff();
        $other = $this->staff();
        $this->app['auth']->forgetGuards();

        $note = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/admin/notes', ['workspace_id' => $ws->id, 'body' => 'Called owner about GBP access.'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/notes', ['body' => 'Orphan'])->assertStatus(422);
        $this->getJson("/api/v1/admin/workspaces/{$ws->id}")->assertJsonPath('data.notes.0.author.name', 'Ops Person');
        // Customers never see staff notes.
        $this->app['auth']->forgetGuards();
        $this->as($owner, $ws)->getJson('/api/v1/admin/workspaces/'.$ws->id)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/admin/notes/{$note}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'sanctum')->deleteJson("/api/v1/admin/notes/{$note}")->assertNoContent();

        DB::table('failed_jobs')->insert(['uuid' => 'job-1', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\RunWebsiteAudit']), 'exception' => "RuntimeException: Boom\n#0 trace with secrets", 'failed_at' => now()]);
        $jobs = $this->getJson('/api/v1/admin/failed-jobs')->assertOk()->assertJsonPath('data.0.job', 'RunWebsiteAudit')->assertJsonPath('data.0.error', 'RuntimeException: Boom');
        $this->assertStringNotContainsString('trace with secrets', $jobs->getContent());
        $this->deleteJson('/api/v1/admin/failed-jobs/job-1')->assertNoContent();
        $this->deleteJson('/api/v1/admin/failed-jobs/job-1')->assertNotFound();

        DB::table('crawl_runs')->where('business_id', $id)->update(['status' => 'failed', 'error' => 'Timed out']);
        $this->postJson("/api/v1/admin/workspaces/{$ws->id}/businesses/{$id}/audit")->assertStatus(202);
        $this->assertSame($staff->id, DB::table('crawl_runs')->latest('id')->value('requested_by'));
        $this->postJson("/api/v1/admin/workspaces/{$ws->id}/businesses/{$id}/audit")->assertStatus(422);
    }
}
