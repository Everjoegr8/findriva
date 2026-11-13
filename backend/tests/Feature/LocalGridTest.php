<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\LocalGridScan;
use App\Models\UsageEvent;
use App\Services\Rankings\LocalGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocalGridTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 6.4474;

    private const LNG = 3.4723;

    private function business($user, $ws): int
    {
        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://glow.example', 'start_audit' => false,
            'contacts' => [['type' => 'phone', 'value' => '08031234567', 'is_primary' => true]],
            'locations' => [['location_type' => 'storefront', 'city' => 'Lagos', 'latitude' => self::LAT, 'longitude' => self::LNG, 'is_primary' => true]],
        ])->assertCreated()->json('data.id');
    }

    private function configure(): void
    {
        config(['findriva.rankings.provider' => 'dataforseo', 'findriva.keywords.dataforseo.login' => 'l', 'findriva.keywords.dataforseo.password' => 'p']);
    }

    private static function places(array $items): array
    {
        return ['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'cost' => 0.002, 'result' => [['items' => array_map(fn ($i) => $i + ['type' => 'maps_search'], $items)]]]]];
    }

    public function test_grid_points_are_spread_evenly_around_the_centre(): void
    {
        $pts = LocalGrid::points(self::LAT, self::LNG, 3, 1.0);
        $this->assertCount(9, $pts);
        $this->assertEqualsWithDelta(self::LAT, $pts[4]['lat'], 1e-7);
        $this->assertEqualsWithDelta(self::LNG, $pts[4]['lng'], 1e-7);
        $this->assertGreaterThan($pts[4]['lat'], $pts[1]['lat'], 'row 0 is north');
        $this->assertGreaterThan($pts[4]['lng'], $pts[5]['lng'], 'last column is east');
        // ~1 km apart north-south.
        $this->assertEqualsWithDelta(1.0, ($pts[1]['lat'] - $pts[4]['lat']) * 110.574, 0.01);
    }

    public function test_scan_finds_the_business_by_site_phone_or_exact_name_and_lists_leaders(): void
    {
        $this->configure();
        $calls = [];
        Http::fake(['api.dataforseo.com/*' => function (Request $r) use (&$calls) {
            $calls[] = $r->data()[0];
            $n = count($calls);

            return Http::response(self::places(match (true) {
                $n <= 3 => [
                    ['rank_group' => 1, 'title' => 'Faces by Temi', 'url' => 'https://facesbytemi.com/', 'rating' => ['value' => 4.8, 'votes_count' => 120]],
                    ['rank_group' => 2, 'title' => 'Glow By Ada Studio', 'url' => 'https://www.glow.example/', 'phone' => '+234 803 123 4567'],
                ],
                $n <= 6 => [
                    ['rank_group' => 1, 'title' => 'Glow by Ada', 'domain' => 'instagram.com'],
                    ['rank_group' => 2, 'title' => 'Faces by Temi', 'url' => 'https://facesbytemi.com/'],
                ],
                default => [
                    ['rank_group' => 1, 'title' => 'Faces by Temi', 'url' => 'https://facesbytemi.com/'],
                    ['rank_group' => 2, 'title' => 'Glow by Adaeze Hair', 'phone' => '0809 000 0000'],
                ],
            }));
        }]);
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $loc = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/local-grid")->assertOk()->json('locations.0.id');

        $scanId = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", ['keyword' => 'makeup artist', 'location_id' => $loc, 'size' => 3])
            ->assertStatus(202)->json('data.id');
        $this->assertCount(9, $calls);
        $this->assertMatchesRegularExpression('/^6\.\d+,3\.\d+,15z$/', $calls[0]['location_coordinate']);

        $scan = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/local-grid/{$scanId}")->assertOk()->json('data');
        $this->assertSame('completed', $scan['status']);
        $this->assertSame(6, $scan['points_found'], 'website/phone and exact name match; a similar name does not');
        $this->assertEquals(1.5, $scan['average_position']);
        $this->assertSame(6, $scan['top_3_points']);
        $this->assertSame([2, 2, 2, 1, 1, 1, null, null, null], array_column($scan['points'], 'position'));
        $this->assertSame('Faces by Temi', $scan['leaders'][0]['title']);
        $this->assertSame(9, $scan['leaders'][0]['points_in_top_3']);
        $this->assertSame(9, (int) UsageEvent::withoutGlobalScopes()->where('action', 'local_grid_point')->sum('units'));
    }

    public function test_monthly_points_plan_and_provider_limits(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $body = ['keyword' => 'makeup artist', 'lat' => self::LAT, 'lng' => self::LNG, 'size' => 5];
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", $body)->assertStatus(422)->assertJsonValidationErrors('grid');

        $this->configure();
        config(['findriva.plans.growth.local_grid_points_per_month' => 30]);
        Http::fake(['api.dataforseo.com/*' => Http::response(self::places([['rank_group' => 1, 'title' => 'Other']]))]);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", $body)->assertStatus(202);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", $body)->assertStatus(422);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", ['size' => 3] + $body)->assertStatus(422);
        $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/local-grid")->assertJsonPath('allowance.used_this_month', 25);

        [$freeUser, $freeWs] = $this->member(plan: 'free');
        $freeId = $this->business($freeUser, $freeWs);
        $this->as($freeUser, $freeWs)->postJson("/api/v1/businesses/{$freeId}/local-grid", $body)->assertStatus(422);
    }

    public function test_provider_failure_keeps_checked_points_and_charges_only_those(): void
    {
        $this->configure();
        $n = 0;
        Http::fake(['api.dataforseo.com/*' => function () use (&$n) {
            return ++$n <= 4 ? Http::response(self::places([['rank_group' => 3, 'title' => 'Glow by Ada']])) : Http::response([], 500);
        }]);
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $scanId = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", ['keyword' => 'makeup', 'lat' => self::LAT, 'lng' => self::LNG])->json('data.id');
        $scan = LocalGridScan::withoutGlobalScopes()->findOrFail($scanId);
        $this->assertSame('partial', $scan->status);
        $this->assertCount(4, $scan->points);
        $this->assertSame(4, (int) UsageEvent::withoutGlobalScopes()->where('action', 'local_grid_point')->sum('units'));
    }

    public function test_permissions_and_isolation(): void
    {
        $this->configure();
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        $this->as($viewer, $ws)->postJson("/api/v1/businesses/{$id}/local-grid", ['keyword' => 'makeup', 'lat' => 6.4, 'lng' => 3.4])->assertForbidden();
        [$other, $otherWs] = $this->member();
        $this->as($other, $otherWs)->getJson("/api/v1/businesses/{$id}/local-grid")->assertNotFound();
    }
}
