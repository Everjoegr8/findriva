<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiQuestion;
use App\Models\Business;
use App\Services\AiVisibility\AiVisibility;
use App\Services\AiVisibility\AnswerEngine;
use App\Services\AiVisibility\ClaudeAnswerEngine;
use App\Services\AiVisibility\NullAnswerEngine;
use App\Services\Content\ClaudeContentGenerator;
use App\Services\Content\ContentGenerator;
use App\Services\Content\TemplateContentGenerator;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OptionalAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owners_can_switch_ai_off_for_their_workspace_and_the_no_ai_versions_take_over(): void
    {
        Queue::fake();
        config(['services.anthropic.key' => 'test-key', 'findriva.ai_answers.engine' => 'claude', 'findriva.content.generator' => 'claude']);
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Glow by Ada', 'primary_category' => 'Makeup artist'])->json('data.id');

        app(CurrentWorkspace::class)->runAs($ws, function () {
            $this->assertInstanceOf(ClaudeContentGenerator::class, app(ContentGenerator::class));
            $this->assertInstanceOf(ClaudeAnswerEngine::class, app(AnswerEngine::class));
        });

        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        $this->as($viewer, $ws)->patchJson('/api/v1/workspace', ['settings' => ['ai_enabled' => false]])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->as($owner, $ws)->patchJson('/api/v1/workspace', ['settings' => ['ai_enabled' => false, 'anything_else' => 'x']])->assertOk()
            ->assertJsonPath('data.settings.ai_enabled', false)->assertJsonMissingPath('data.settings.anything_else');
        $ws->refresh();
        $this->assertFalse($ws->aiEnabled());

        app(CurrentWorkspace::class)->runAs($ws, function () use ($id) {
            $this->assertInstanceOf(TemplateContentGenerator::class, app(ContentGenerator::class));
            $this->assertInstanceOf(NullAnswerEngine::class, app(AnswerEngine::class));
            $business = Business::query()->findOrFail($id);
            AiQuestion::query()->create(['business_id' => $id, 'question' => 'Who is the best makeup artist in Lekki?', 'active' => true]);
            $this->assertSame('workspace_off', app(AiVisibility::class)->run($business)['status']);
        });

        // The facts-only draft still works with AI off.
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'business_description'])->assertSuccessful()
            ->assertJsonPath('data.versions.0.generator', 'template');
    }

    public function test_autocomplete_is_rule_based_and_tenant_scoped(): void
    {
        Queue::fake();
        [$owner, $ws] = $this->member();
        $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Ada Studio', 'primary_category' => 'Makeup artistry studio'])->assertCreated();
        [$other, $otherWs] = $this->member();
        $this->app['auth']->forgetGuards();
        $this->as($other, $otherWs)->postJson('/api/v1/businesses', ['name' => 'Secret', 'primary_category' => 'Makeup secret lab'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $cats = $this->as($owner, $ws)->getJson('/api/v1/suggest/categories?q=make')->assertOk()->json('data');
        $this->assertSame('Makeup artistry studio', $cats[0]);
        $this->assertContains('Makeup artist', $cats);
        $this->assertNotContains('Makeup secret lab', $cats);

        $this->assertContains('Lekki', $this->as($owner, $ws)->getJson('/api/v1/suggest/cities?q=lek')->json('data'));
        $this->assertContains('Bridal makeup', $this->as($owner, $ws)->getJson('/api/v1/suggest/services?category=makeup%20artist&q=br')->json('data'));
        $this->assertSame([], $this->as($owner, $ws)->getJson('/api/v1/suggest/services?category=Unknown&q=a')->json('data'));
        $this->as($owner, $ws)->getJson('/api/v1/suggest/passwords')->assertNotFound();
    }
}
