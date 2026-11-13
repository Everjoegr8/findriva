<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Competitor;
use App\Models\Keyword;
use App\Models\RankObservation;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RankingsTest extends TestCase
{
    use RefreshDatabase;

    private function business($user, $ws): int
    {
        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada',
            'primary_category' => 'Makeup artist',
            'website_url' => 'https://glow.example',
            'start_audit' => false,
            'assets' => [['url' => 'https://www.instagram.com/glowbyada/']],
        ])->assertCreated()->json('data.id');
    }

    private function track($ws, int $businessId, string $phrase, ?int $volume = null): Keyword
    {
        return Keyword::withoutGlobalScopes()->create([
            'workspace_id' => $ws->id, 'business_id' => $businessId, 'keyword' => $phrase, 'normalized' => Keyword::normalize($phrase),
            'intent' => 'local', 'market' => 'NG', 'status' => 'tracked', 'search_volume' => $volume,
        ]);
    }

    private function configure(): void
    {
        config(['findriva.rankings.provider' => 'dataforseo', 'findriva.keywords.dataforseo.login' => 'l', 'findriva.keywords.dataforseo.password' => 'p']);
    }

    /** @param list<array{0: string, 1: int}> $results url, organic position */
    private static function serp(array $results): array
    {
        return ['status_code' => 20000, 'tasks' => [[
            'status_code' => 20000, 'cost' => 0.002,
            'result' => [[
                'item_types' => ['local_pack', 'organic', 'people_also_ask'],
                'items' => array_merge(
                    [['type' => 'local_pack', 'title' => 'Map', 'rank_group' => 1, 'rank_absolute' => 1]],
                    array_map(fn ($r) => ['type' => 'organic', 'rank_group' => $r[1], 'rank_absolute' => $r[1] + 1, 'url' => $r[0], 'domain' => parse_url($r[0], PHP_URL_HOST), 'title' => 'Result | '.parse_url($r[0], PHP_URL_HOST)], $results),
                ),
            ]],
        ]]];
    }

    public function test_without_a_provider_positions_are_not_measured(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $this->track($ws, $id, 'makeup artist lekki');

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check")->assertStatus(202);
        $res = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertOk();
        $res->assertJsonPath('last_run.status', 'not_configured')
            ->assertJsonPath('data.0.checked', false)
            ->assertJsonPath('data.0.position', null)
            ->assertJsonPath('method.configured', false);
        $this->assertSame(0, RankObservation::withoutGlobalScopes()->count());
    }

    public function test_free_plan_does_not_buy_position_checks(): void
    {
        $this->configure();
        [$user, $ws] = $this->member(plan: 'free');
        $id = $this->business($user, $ws);
        $this->track($ws, $id, 'makeup artist lekki');

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check")->assertStatus(202);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertJsonPath('last_run.status', 'plan_excludes');
        Http::assertNothingSent();
    }

    public function test_checks_positions_for_own_site_and_profiles_and_maps_competitors(): void
    {
        $this->configure();
        $requests = [];
        $nextDay = false;
        Http::fake(['api.dataforseo.com/*' => function (Request $r) use (&$requests, &$nextDay) {
            $requests[] = $r->data()[0];
            if ($nextDay) {
                return Http::response(self::serp([['https://glow.example/', 1], ['https://rival.com.ng/', 2]]));
            }

            return Http::response(match ($r->data()[0]['keyword']) {
                'makeup artist lekki' => self::serp([
                    ['https://rival.com.ng/', 1],
                    ['https://www.instagram.com/glowbyada/', 2],
                    ['https://glow.example/services', 3],
                    ['https://jiji.ng/lagos/makeup', 4],
                    ['https://www.instagram.com/otherartist/', 5],
                ]),
                default => self::serp([['https://rival.com.ng/bridal', 2], ['https://beauty.example/', 1]]),
            });
        }]);

        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $k1 = $this->track($ws, $id, 'makeup artist lekki', 900);
        $this->track($ws, $id, 'bridal makeup lagos', 400);

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check")->assertStatus(202);
        $this->assertCount(2, $requests);
        $this->assertSame(2566, $requests[0]['location_code']);
        $this->assertSame('makeup artist lekki', $requests[0]['keyword'], 'most-searched keyword is checked first');

        $res = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertOk();
        $rows = collect($res->json('data'))->keyBy('keyword');
        // The business's own Instagram profile counts; another account on Instagram does not.
        $this->assertSame(2, $rows['makeup artist lekki']['position']);
        $this->assertSame('https://www.instagram.com/glowbyada/', $rows['makeup artist lekki']['url']);
        $this->assertContains('local_pack', $rows['makeup artist lekki']['serp_features']);
        $this->assertTrue($rows['bridal makeup lagos']['checked']);
        $this->assertNull($rows['bridal makeup lagos']['position'], 'not found within depth stays null, not a guess');
        $res->assertJsonPath('summary.top_3', 1)->assertJsonPath('summary.not_found', 1)->assertJsonPath('last_run.status', 'checked');
        $this->assertEquals(0.004, UsageEvent::withoutGlobalScopes()->where('action', 'rank_check')->sum('estimated_cost'));

        $comp = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/competitors")->assertOk()->json('data'))->keyBy('domain');
        $this->assertSame(2, $comp['rival.com.ng']['shared_keywords']);
        $this->assertSame(1, $comp['rival.com.ng']['best_position']);
        $this->assertSame('business', $comp['rival.com.ng']['kind']);
        $this->assertSame('platform', $comp['jiji.ng']['kind']);
        $this->assertSame('platform', $comp['instagram.com']['kind']);
        $this->assertFalse($comp->has('glow.example'), 'own site is never a competitor');
        $h2h = collect($comp['rival.com.ng']['keywords'])->keyBy('keyword');
        $this->assertSame(2, $h2h['makeup artist lekki']['your_position']);
        $this->assertNull($h2h['bridal makeup lagos']['your_position']);

        // A second manual check the same day does not spend again.
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check")->assertStatus(202);
        $this->assertCount(2, $requests);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertJsonPath('last_run.status', 'up_to_date');

        // Next day the position moved up: change is reported against the previous check.
        $this->travel(1)->days();
        $nextDay = true;
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check")->assertStatus(202);
        $rows = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->json('data'))->keyBy('keyword_id');
        $this->assertSame(1, $rows[$k1->id]['position']);
        $this->assertSame(1, $rows[$k1->id]['change']);
        $this->assertCount(2, $rows[$k1->id]['history']);
    }

    public function test_allowance_and_daily_cap_limit_spend(): void
    {
        $this->configure();
        config(['findriva.plans.growth.rank_tracked_keywords' => 1]);
        $sent = 0;
        Http::fake(['api.dataforseo.com/*' => function () use (&$sent) {
            $sent++;

            return Http::response(self::serp([['https://rival.com.ng/', 1]]));
        }]);
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $this->track($ws, $id, 'makeup artist lekki', 900);
        $this->track($ws, $id, 'bridal makeup lagos', 400);

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check");
        $this->assertSame(1, $sent);
        $res = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings");
        $res->assertJsonPath('last_run.over_allowance', 1)->assertJsonPath('data.1.within_allowance', false);

        // Cap: today's spend already at the ceiling.
        $this->travel(1)->days();
        UsageEvent::create(['workspace_id' => $ws->id, 'provider' => 'dataforseo', 'action' => 'rank_check', 'units' => 1, 'estimated_cost' => 0.999]);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check");
        $this->assertSame(1, $sent);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertJsonPath('last_run.status', 'budget_exhausted');
    }

    public function test_provider_error_charges_nothing(): void
    {
        $this->configure();
        Http::fake(['api.dataforseo.com/*' => Http::response(['status_code' => 40100, 'status_message' => 'auth'], 401)]);
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $this->track($ws, $id, 'makeup artist lekki');

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/rankings/check");
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/rankings")->assertJsonPath('last_run.status', 'provider_error');
        $this->assertSame(0, RankObservation::withoutGlobalScopes()->count());
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('action', 'rank_check')->count());
    }

    public function test_competitors_can_be_added_tracked_and_dismissed_within_the_tenant(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/competitors", ['domain' => 'https://www.Rival.com.ng/about'])
            ->assertCreated()->assertJsonPath('data.domain', 'rival.com.ng')->assertJsonPath('data.status', 'tracked');
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/competitors", ['domain' => 'glow.example'])->assertStatus(422);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/competitors", ['domain' => 'not a domain'])->assertStatus(422);

        $c = Competitor::withoutGlobalScopes()->firstOrFail();
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/competitors/{$c->id}", ['status' => 'dismissed'])->assertOk();
        $this->assertCount(0, $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/competitors")->json('data'));
        $this->assertCount(1, $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/competitors?status=all")->json('data'));

        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        $this->as($viewer, $ws)->postJson("/api/v1/businesses/{$id}/competitors", ['domain' => 'x.com.ng'])->assertForbidden();

        [$other, $otherWs] = $this->member();
        $this->as($other, $otherWs)->getJson("/api/v1/businesses/{$id}/competitors")->assertNotFound();
        $this->as($other, $otherWs)->getJson("/api/v1/businesses/{$id}/rankings")->assertNotFound();
    }
}
