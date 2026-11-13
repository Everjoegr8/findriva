<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiAnswerCheck;
use App\Models\AiQuestion;
use App\Models\Keyword;
use App\Models\UsageEvent;
use App\Services\AiVisibility\AnswerEngine;
use App\Services\AiVisibility\ClaudeAnswerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAnswerEngine;
use Tests\TestCase;

class AiAnswersTest extends TestCase
{
    use RefreshDatabase;

    private function business($user, $ws): int
    {
        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://glow.example', 'start_audit' => false,
            'aliases' => [['alias' => 'Glow Studio']],
            'locations' => [['location_type' => 'storefront', 'city' => 'Lagos', 'is_primary' => true]],
            'assets' => [['url' => 'https://www.instagram.com/glowbyada/']],
        ])->assertCreated()->json('data.id');
    }

    private function engine(array $answers = []): FakeAnswerEngine
    {
        $e = new FakeAnswerEngine($answers);
        $this->app->instance(AnswerEngine::class, $e);

        return $e;
    }

    public function test_without_an_assistant_nothing_is_asked(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => 'Who does bridal makeup in Lekki?'])->assertCreated();
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check")->assertStatus(202);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")
            ->assertJsonPath('last_run.status', 'not_configured')->assertJsonPath('engine.configured', false)->assertJsonPath('data.0.latest', null);
    }

    public function test_questions_come_from_tracked_keywords_and_never_name_the_business(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        foreach ([['bridal makeup lekki', 'local'], ['glow by ada', 'navigational'], ['who does gele in ajah', 'local']] as [$k, $intent]) {
            Keyword::withoutGlobalScopes()->create(['workspace_id' => $ws->id, 'business_id' => $id, 'keyword' => $k, 'normalized' => $k, 'intent' => $intent, 'market' => 'NG', 'status' => 'tracked']);
        }
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions/suggest")->assertOk()->assertJsonPath('data.added', 2);
        $qs = AiQuestion::withoutGlobalScopes()->pluck('question')->all();
        $this->assertContains('Can you recommend a good bridal makeup lekki?', $qs);
        $this->assertContains('Who does gele in ajah?', $qs);
        $this->assertCount(2, $qs, 'brand searches are not turned into questions');

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => 'Is Glow Studio good for bridal makeup?'])->assertStatus(422);
    }

    public function test_answer_records_whether_the_business_is_named_and_cited_and_who_else_is(): void
    {
        $q = 'Who does bridal makeup in Lekki?';
        $engine = $this->engine([$q => [
            'text' => "Here are some options in Lekki:\n1. **Faces by Temi** - known for soft glam.\n2. **Glow by Ada** - bridal looks and gele.\n",
            'sources' => [
                ['url' => 'https://facesbytemi.com/', 'title' => 'Faces by Temi', 'cited' => true],
                ['url' => 'https://www.instagram.com/glowbyada/', 'title' => 'Glow by Ada (@glowbyada)', 'cited' => true],
                ['url' => 'https://jiji.ng/lagos/makeup', 'title' => 'Makeup artists in Lagos', 'cited' => true],
                ['url' => 'https://www.instagram.com/someoneelse/', 'title' => 'Other', 'cited' => false],
            ],
            'searches' => 2, 'cost' => 0.061,
        ]]);
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => $q])->assertCreated();
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => 'Where can I get gele tied in Ajah?'])->assertCreated();

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check")->assertStatus(202);
        $this->assertSame('Lagos', $engine->asked[0]['location']['city']);
        $this->assertStringNotContainsString('Glow', $engine->asked[0]['question']);

        $res = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertOk();
        $rows = collect($res->json('data'))->keyBy('question');
        $hit = $rows[$q]['latest'];
        $this->assertTrue($hit['named']);
        $this->assertTrue($hit['own_site_cited'], 'its own Instagram profile counts as its own site');
        $this->assertStringContainsString('Glow by Ada', $hit['excerpt']);
        $kinds = collect($hit['sources'])->keyBy('domain');
        $this->assertSame('other', $kinds['facesbytemi.com']['kind']);
        $this->assertSame('platform', $kinds['jiji.ng']['kind']);
        $this->assertFalse($rows['Where can I get gele tied in Ajah?']['latest']['named']);
        $res->assertJsonPath('summary.asked', 2)->assertJsonPath('summary.named', 1)->assertJsonPath('last_run.status', 'checked');
        $this->assertContains('facesbytemi.com', collect($res->json('often_cited'))->pluck('domain')->all());
        $this->assertEqualsWithDelta(0.111, UsageEvent::withoutGlobalScopes()->where('action', 'ai_answer_check')->sum('estimated_cost'), 1e-6);

        // Asking again the same day does not spend again.
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check");
        $this->assertCount(2, $engine->asked);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertJsonPath('last_run.status', 'up_to_date');
    }

    public function test_plan_allowance_cap_and_errors(): void
    {
        $engine = $this->engine();
        [$user, $ws] = $this->member(plan: 'free');
        $id = $this->business($user, $ws);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => 'Who does bridal makeup in Lekki?']);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check");
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertJsonPath('last_run.status', 'plan_excludes');
        $this->assertCount(0, $engine->asked);

        $ws->update(['plan' => 'growth']);
        config(['findriva.plans.growth.ai_answer_questions' => 1]);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => 'Where can I get gele tied in Ajah?']);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check");
        $this->assertCount(1, $engine->asked);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertJsonPath('last_run.over_allowance', 1);

        $this->travel(1)->days();
        UsageEvent::create(['workspace_id' => $ws->id, 'provider' => 'claude', 'action' => 'ai_answer_check', 'units' => 1, 'estimated_cost' => 1.95]);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check");
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertJsonPath('last_run.status', 'budget_exhausted');

        $this->travel(1)->days();
        $engine->fail = true;
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/ai-answers/check");
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertJsonPath('last_run.status', 'engine_error');
        $this->assertSame(1, AiAnswerCheck::withoutGlobalScopes()->count());
    }

    public function test_permissions_and_isolation(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        $this->as($viewer, $ws)->postJson("/api/v1/businesses/{$id}/ai-questions", ['question' => 'Who does bridal makeup in Lekki?'])->assertForbidden();
        $this->as($viewer, $ws)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertOk();
        [$other, $otherWs] = $this->member();
        $this->as($other, $otherWs)->getJson("/api/v1/businesses/{$id}/ai-answers")->assertNotFound();
    }

    public function test_claude_response_parsing_marks_cited_sources(): void
    {
        $o = fn (array $a) => json_decode(json_encode($a));
        $parsed = ClaudeAnswerEngine::parse([
            $o(['type' => 'server_tool_use', 'name' => 'web_search']),
            $o(['type' => 'web_search_tool_result', 'content' => [['type' => 'web_search_result', 'url' => 'https://a.example/', 'title' => 'A'], ['type' => 'web_search_result', 'url' => 'https://b.example/', 'title' => 'B']]]),
            $o(['type' => 'text', 'text' => 'Try A.', 'citations' => [['type' => 'web_search_result_location', 'url' => 'https://a.example/', 'title' => 'A', 'cited_text' => 'x']]]),
            $o(['type' => 'text', 'text' => ' Thanks.', 'citations' => null]),
        ]);
        $this->assertSame('Try A. Thanks.', $parsed['text']);
        $this->assertSame([['url' => 'https://a.example/', 'title' => 'A', 'cited' => true], ['url' => 'https://b.example/', 'title' => 'B', 'cited' => false]], $parsed['sources']);
    }
}
