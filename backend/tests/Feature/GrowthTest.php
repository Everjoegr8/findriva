<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\ProviderCache;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GrowthTest extends TestCase
{
    use RefreshDatabase;

    private function business($user, $ws, array $extra = []): int
    {
        Queue::fake();

        return $this->as($user, $ws)->postJson('/api/v1/businesses', array_merge([
            'name' => 'Glow by Ada',
            'primary_category' => 'Makeup artist',
            'description' => 'Bridal and event makeup artist serving Lekki, Ajah and Victoria Island in Lagos.',
            'locations' => [
                ['label' => 'Lekki studio', 'address_line1' => '12 Admiralty Way', 'city' => 'Lagos', 'region' => 'Lagos', 'location_type' => 'storefront', 'address_is_public' => false,
                    'service_areas' => ['Lekki', 'Ajah'], 'opening_hours' => ['mon' => ['09:00-18:00'], 'tue' => ['09:00-18:00'], 'sat' => ['10:00-16:00']]],
            ],
            'contacts' => [['type' => 'whatsapp', 'value' => '0803 123 4567', 'is_primary' => true]],
            'services' => [['name' => 'Bridal makeup', 'description' => 'Full bridal look with trial session.'], ['name' => 'Gele tying']],
            'assets' => [['url' => 'https://www.instagram.com/glowbyada/']],
        ], $extra))->json('data.id');
    }

    public function test_fixes_are_built_from_facts_and_respect_private_addresses(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws, ['website_url' => 'https://glow.example']);

        $fixes = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/fixes")->assertOk()->json('data'))->keyBy('key');
        $this->assertEqualsCanonicalizing(['local_business_jsonld', 'robots_txt', 'sitemap_xml', 'head_tags'], $fixes->keys()->all());

        $jsonLd = $fixes['local_business_jsonld'];
        $this->assertSame('BeautySalon', $jsonLd['schema_type']);
        $data = json_decode(trim(str_replace(['<script type="application/ld+json">', '</script>'], '', $jsonLd['content'])), true);
        $this->assertSame('Glow by Ada', $data['name']);
        $this->assertSame('+2348031234567', $data['telephone']);
        $this->assertArrayNotHasKey('streetAddress', $data['address'], 'Private street address must never be published.');
        $this->assertSame('Lagos', $data['address']['addressLocality']);
        $this->assertSame(['Lagos', 'Lekki', 'Ajah'], array_column($data['areaServed'], 'name'));
        $this->assertSame(['Monday', 'Tuesday'], $data['openingHoursSpecification'][0]['dayOfWeek']);
        $this->assertSame(['https://www.instagram.com/glowbyada/'], $data['sameAs'], 'Only http(s) links reach generated markup.');
        $this->assertContains('image', array_column($jsonLd['missing_facts'], 'key'));

        $this->assertStringContainsString('Sitemap: https://glow.example/sitemap.xml', $fixes['robots_txt']['content']);
        $this->assertStringContainsString('<loc>https://glow.example/</loc>', $fixes['sitemap_xml']['content']);
        $this->assertStringContainsString('<title>Glow by Ada | Makeup artist in Lagos</title>', $fixes['head_tags']['content']);
    }

    public function test_business_without_website_only_gets_structured_data(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $res = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/fixes")->assertOk();
        $this->assertSame(['local_business_jsonld'], array_column($res->json('data'), 'key'));
        $this->assertFalse($res->json('has_website'));
    }

    public function test_publishing_needs_approval_rights_and_a_complete_profile(): void
    {
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $thin = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Thin Biz'])->json('data.id');
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$thin}/public-page")->assertUnprocessable()->assertJsonValidationErrors('business');

        $id = $this->business($owner, $ws);
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/public-page")->assertForbidden();
        $state = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/public-page")->assertOk()->json('data');
        $this->assertTrue($state['is_published']);
        $this->assertSame('glow-by-ada-lagos', $state['slug']);

        $html = $this->get('/b/glow-by-ada-lagos')->assertOk()->getContent();
        $this->assertStringContainsString('<h1>Glow by Ada</h1>', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/b/glow-by-ada-lagos">', $html);
        $this->assertStringContainsString('https://wa.me/2348031234567', $html);
        $this->assertStringNotContainsString('Admiralty', $html, 'Private address stays private on the public page.');
        $this->assertStringNotContainsString('javascript:', $html);

        $this->get('/sitemap.xml')->assertOk()->assertSee('http://localhost/b/glow-by-ada-lagos', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: http://localhost/sitemap.xml', false);

        // Another workspace cannot take the same address.
        [$other, $ws2] = $this->member();
        $id2 = $this->business($other, $ws2);
        $this->as($other, $ws2)->postJson("/api/v1/businesses/{$id2}/public-page", ['slug' => 'glow-by-ada-lagos'])->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->as($owner, $ws)->deleteJson("/api/v1/businesses/{$id}/public-page")->assertOk()->assertJsonPath('data.is_published', false);
        $this->get('/b/glow-by-ada-lagos')->assertNotFound();
    }

    public function test_keyword_seeds_without_a_provider_are_never_given_numbers(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $summary = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/keywords/research")->assertOk()->json('data');
        $this->assertSame('not_configured', $summary['status']);
        $this->assertGreaterThan(5, $summary['seeds']);

        $res = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/keywords")->assertOk();
        $kw = collect($res->json('data'))->keyBy('keyword');
        $this->assertTrue($kw->has('bridal makeup in lekki'));
        $this->assertSame('local', $kw['bridal makeup in lekki']['intent']);
        $this->assertSame('navigational', $kw['glow by ada']['intent']);
        $this->assertSame('informational', $kw['how to choose bridal makeup']['intent']);
        $this->assertTrue($kw->every(fn ($k) => $k['search_volume'] === null && $k['measured'] === false));
        $this->assertNotEmpty($res->json('clusters'));
        Http::assertNothingSent();
    }

    public function test_dataforseo_measurements_are_cached_and_capped_per_workspace(): void
    {
        config(['findriva.keywords.provider' => 'dataforseo', 'findriva.keywords.dataforseo.login' => 'l', 'findriva.keywords.dataforseo.password' => 'p']);
        $calls = 0;
        Http::fake(['api.dataforseo.com/*' => function (Request $r) use (&$calls) {
            $calls++;
            $this->assertSame(2566, $r->data()[0]['location_code']);
            $this->assertLessThanOrEqual(20, count($r->data()[0]['keywords']));

            return Http::response(['status_code' => 20000, 'tasks' => [[
                'status_code' => 20000, 'cost' => 0.075,
                'result' => [
                    ['keyword' => 'bridal makeup in lekki', 'search_volume' => 320, 'competition_index' => 45, 'cpc' => 0.31],
                    ['keyword' => 'bridal makeup artist lagos', 'search_volume' => 880, 'competition_index' => 60, 'cpc' => 0.42],
                ],
            ]]]);
        }]);

        [$user, $ws] = $this->member(); // growth plan: $2/day
        $id = $this->business($user, $ws);
        $first = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/keywords/research")->assertOk()->json('data');
        $this->assertSame('measured', $first['status']);
        $this->assertSame(1, $first['ideas_added']);

        $kw = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/keywords")->json('data'))->keyBy('keyword');
        $this->assertSame(880, $kw['bridal makeup artist lagos']['search_volume']);
        $this->assertSame('dataforseo', $kw['bridal makeup artist lagos']['metrics_provider']);
        $this->assertSame(0.45, $kw['bridal makeup in lekki']['competition']);
        $this->assertSame('seed', $kw['bridal makeup in lekki']['origin']);
        $this->assertNull($kw['gele tying cost']['search_volume']);

        // Owner's choice survives a re-run, and the cached response costs nothing.
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/keywords/".$kw['bridal makeup in lekki']['id'], ['status' => 'tracked'])->assertOk();
        $second = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/keywords/research")->json('data');
        $this->assertSame('cached', $second['status']);
        $this->assertSame(1, $calls);
        $this->assertSame('tracked', Keyword::withoutGlobalScopes()->find($kw['bridal makeup in lekki']['id'])->status);

        // Spend cap: once today's budget is used, nothing more is bought.
        ProviderCache::query()->delete();
        UsageEvent::create(['workspace_id' => $ws->id, 'provider' => 'dataforseo', 'action' => 'keyword_research', 'units' => 1, 'estimated_cost' => 1.99]);
        $third = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/keywords/research")->json('data');
        $this->assertSame('budget_exhausted', $third['status']);
        $this->assertSame(1, $calls);

        // Free plan never spends money on keyword data.
        [$freeUser, $freeWs] = $this->member(plan: 'free');
        $freeId = $this->business($freeUser, $freeWs);
        $this->assertSame('plan_excludes', $this->as($freeUser, $freeWs)->postJson("/api/v1/businesses/{$freeId}/keywords/research")->json('data.status'));
        $this->assertSame(1, $calls);
    }

    public function test_provider_failure_is_reported_and_not_charged(): void
    {
        config(['findriva.keywords.provider' => 'dataforseo', 'findriva.keywords.dataforseo.login' => 'l', 'findriva.keywords.dataforseo.password' => 'p']);
        Http::fake(['api.dataforseo.com/*' => Http::response(['status_code' => 40100, 'status_message' => 'auth'], 401)]);
        [$user, $ws] = $this->member();
        $id = $this->business($user, $ws);
        $this->assertSame('provider_error', $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/keywords/research")->json('data.status'));
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->where('action', 'keyword_research')->count());
    }

    public function test_keywords_are_tenant_isolated(): void
    {
        [$user, $ws] = $this->member();
        [$other, $ws2] = $this->member();
        $id = $this->business($user, $ws);
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/keywords/research")->assertOk();
        $kwId = Keyword::withoutGlobalScopes()->value('id');
        $id2 = $this->business($other, $ws2);
        $this->as($other, $ws2)->patchJson("/api/v1/businesses/{$id2}/keywords/{$kwId}", ['status' => 'dismissed'])->assertNotFound();
        $this->as($other, $ws2)->getJson("/api/v1/businesses/{$id}/keywords")->assertNotFound();
    }
}
