<?php

namespace Tests\Feature;

use App\Jobs\RunLocalGridScan;
use App\Models\AuditLog;
use App\Models\LocalGridScan;
use App\Models\User;
use App\Services\AiVisibility\AnswerEngine;
use App\Services\AiVisibility\ClaudeAnswerEngine;
use App\Services\AiVisibility\NullAnswerEngine;
use App\Services\Content\ContentGenerator;
use App\Services\Content\TemplateContentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformControlTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_platform_admin' => true])->save();

        return $u;
    }

    private function limits(array $over = []): array
    {
        return array_merge([
            'businesses' => 3, 'locations_per_business' => 4, 'pages_per_crawl' => 200, 'crawls_per_month' => 40, 'content_generations_per_day' => 50,
            'keyword_spend_usd_per_day' => 0.75, 'rank_tracked_keywords' => 30, 'rank_spend_usd_per_day' => 0.4, 'ai_answer_questions' => 8,
            'ai_answer_spend_usd_per_day' => 0.6, 'local_grid_points_per_month' => 150,
        ], $over);
    }

    public function test_staff_change_plan_limits_and_price_and_customers_see_them(): void
    {
        [$owner, $ws] = $this->member(plan: 'starter');
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')->putJson('/api/v1/admin/platform/plans/starter', ['label' => 'Starter', 'limits' => $this->limits(['businesses' => -1]), 'price_kobo' => 1200000])
            ->assertUnprocessable()->assertJsonValidationErrors('limits.businesses');
        $this->putJson('/api/v1/admin/platform/plans/starter', ['label' => 'Starter', 'limits' => $this->limits(), 'price_kobo' => 1200050])
            ->assertUnprocessable()->assertJsonValidationErrors('price_kobo');
        $this->putJson('/api/v1/admin/platform/plans/nonsense', ['label' => 'X', 'limits' => $this->limits()])->assertNotFound();

        $this->putJson('/api/v1/admin/platform/plans/starter', ['label' => 'Starter plus', 'limits' => $this->limits(), 'price_kobo' => 1200000])
            ->assertOk()->assertJsonPath('data.limits.businesses', 3)->assertJsonPath('data.price_kobo', 1200000);
        $this->assertTrue(AuditLog::query()->where('action', 'platform.settings_updated')->where('actor_id', $staff->id)->exists());

        $this->assertSame(3, $ws->fresh()->entitlements()['businesses']);
        $this->app['auth']->forgetGuards();
        $this->as($owner, $ws)->getJson('/api/v1/coverage')->assertOk()->assertJsonPath('data.limits.businesses', 3);
        $billing = $this->as($owner, $ws)->getJson('/api/v1/workspace/billing')->assertOk()->json('data.plans');
        $starter = collect($billing)->firstWhere('key', 'starter');
        $this->assertSame(1200000, $starter['price']);
        $this->assertSame('Starter plus', $starter['label']);
    }

    public function test_a_switched_off_module_is_refused_and_its_jobs_stand_down(): void
    {
        Queue::fake();
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Glow by Ada', 'primary_category' => 'Makeup artist'])->json('data.id');
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/keywords")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff(), 'sanctum')->putJson('/api/v1/admin/platform/modules', ['keywords' => false, 'map_grid' => false])
            ->assertOk()->assertJsonPath('data.keywords', false)->assertJsonPath('data.rankings', true);
        $this->getJson('/api/v1/platform')->assertOk()->assertJsonPath('data.modules.keywords', false);

        $this->app['auth']->forgetGuards();
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/keywords")->assertForbidden()->assertJsonPath('module', 'keywords');
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertOk();
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}")->assertOk();

        $scan = LocalGridScan::withoutGlobalScopes()->create([
            'workspace_id' => $ws->id, 'business_id' => $id, 'keyword' => 'makeup artist', 'center_lat' => 6.5, 'center_lng' => 3.4,
            'size' => 3, 'spacing_km' => 1, 'depth' => 20, 'provider' => 'none', 'status' => 'queued',
        ]);
        app()->call([new RunLocalGridScan($ws->id, $scan->id), 'handle']);
        $this->assertSame('failed', $scan->fresh()->status);
    }

    public function test_paused_sign_ups_and_announcement(): void
    {
        $this->actingAs($this->staff(), 'sanctum')->putJson('/api/v1/admin/platform/general', ['signups_open' => false, 'announcement' => '  Maintenance tonight at 11pm.  '])->assertOk();
        $this->getJson('/api/v1/platform')->assertJsonPath('data.signups_open', false)->assertJsonPath('data.announcement', 'Maintenance tonight at 11pm.');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', ['name' => 'New Person', 'email' => 'new@example.com', 'password' => 'a-strong-password-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_ai_model_choice_is_applied_and_keys_never_leave_the_server(): void
    {
        config(['services.anthropic.key' => 'sk-ant-very-secret', 'services.paystack.secret_key' => 'sk_live_very_secret']);
        $staff = $this->staff();
        $this->actingAs($staff, 'sanctum')->putJson('/api/v1/admin/platform/ai', ['answers_enabled' => true, 'answers_model' => 'gpt-9', 'content_engine' => 'claude', 'content_model' => 'claude-opus-5-5'])
            ->assertUnprocessable();
        $this->putJson('/api/v1/admin/platform/ai', ['answers_enabled' => true, 'answers_model' => 'claude-sonnet-5-5', 'content_engine' => 'template', 'content_model' => 'claude-opus-5-5'])->assertOk();

        $engine = app(AnswerEngine::class);
        $this->assertInstanceOf(ClaudeAnswerEngine::class, $engine);
        $this->assertSame('claude-sonnet-5-5', $engine->model());
        $this->assertInstanceOf(TemplateContentGenerator::class, app(ContentGenerator::class));

        $this->putJson('/api/v1/admin/platform/ai', ['answers_enabled' => false, 'answers_model' => 'claude-sonnet-5-5', 'content_engine' => 'template', 'content_model' => 'claude-opus-5-5'])->assertOk();
        $this->assertInstanceOf(NullAnswerEngine::class, app(AnswerEngine::class));

        $body = $this->getJson('/api/v1/admin/platform')->assertOk()
            ->assertJsonPath('data.ai.key_configured', true)
            ->assertJsonPath('data.providers.0.configured', true)->getContent();
        $this->assertStringNotContainsString('very-secret', $body);
        $this->assertStringNotContainsString('very_secret', $body);
    }
}
