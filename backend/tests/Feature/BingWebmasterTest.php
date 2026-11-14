<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\SyncBingWebmaster;
use App\Models\IntegrationConnection;
use App\Models\SearchPerformance;
use App\Services\Integrations\BingWebmaster;
use App\Services\Integrations\BingWebmasterImporter;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BingWebmasterTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Request> */
    private array $calls = [];

    private bool $expired = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bing_webmaster.client_id' => 'bing-cid', 'services.bing_webmaster.client_secret' => 'bing-secret',
            'services.bing_webmaster.redirect' => 'http://localhost/api/v1/integrations/bing/callback',
            'findriva.app_url' => 'http://app.test',
        ]);
    }

    private function ms(string $date): string
    {
        // Bing's format: midnight Pacific time, written as UTC milliseconds plus the offset.
        return '/Date('.((strtotime($date.' 00:00:00 UTC') + 8 * 3600) * 1000).'-0800)/';
    }

    private function fakeBing(): void
    {
        Http::fake(function (Request $r) {
            $this->calls[] = $r;
            $url = $r->url();
            if ($url === 'https://www.bing.com/webmasters/oauth/token') {
                return $r['grant_type'] === 'refresh_token'
                    ? Http::response(['access_token' => 'bing-fresh', 'expires_in' => 3599, 'token_type' => 'Bearer'])
                    : Http::response(['access_token' => 'bing-access', 'refresh_token' => 'bing-refresh', 'expires_in' => 3599, 'token_type' => 'bearer']);
            }
            if ($this->expired && $r->hasHeader('Authorization', 'Bearer bing-access')) {
                return Http::response('expired', 401);
            }
            if (str_contains($url, '/GetUserSites')) {
                return Http::response(['d' => [
                    ['Url' => 'https://www.glow.example/', 'IsVerified' => false],
                    ['Url' => 'https://glow.example/', 'IsVerified' => true],
                    ['Url' => 'https://other.example/', 'IsVerified' => true],
                ]]);
            }
            if (str_contains($url, '/GetRankAndTrafficStats')) {
                $rows = [];
                for ($i = 0; $i < 60; $i++) {
                    $rows[] = ['Date' => $this->ms(now()->subDays(2 + $i)->toDateString()), 'Clicks' => $i < 28 ? 3 : 1, 'Impressions' => 50];
                }

                return Http::response(['d' => $rows]);
            }
            if (str_contains($url, '/GetQueryStats')) {
                return Http::response(['d' => [
                    ['Query' => 'makeup artist lekki', 'Date' => $this->ms(now()->subDays(9)->toDateString()), 'Clicks' => 4, 'Impressions' => 40, 'AvgImpressionPosition' => 3, 'AvgClickPosition' => 2],
                    ['Query' => 'makeup artist lekki', 'Date' => $this->ms(now()->subDays(16)->toDateString()), 'Clicks' => 2, 'Impressions' => 60, 'AvgImpressionPosition' => 8, 'AvgClickPosition' => 5],
                    ['Query' => 'gele ajah', 'Date' => $this->ms(now()->subDays(9)->toDateString()), 'Clicks' => 0, 'Impressions' => 10, 'AvgImpressionPosition' => 14, 'AvgClickPosition' => 0],
                ]]);
            }

            return Http::response('unexpected '.$url, 500);
        });
    }

    private function business($user, $ws): int
    {
        Queue::fake();

        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://www.glow.example',
            'contacts' => [['type' => 'phone', 'value' => '08031234567']],
        ])->json('data.id');
    }

    private function connect($user, $ws, int $id): IntegrationConnection
    {
        $url = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/integrations/bing/connect")->assertOk()->json('data.authorization_url');
        $this->assertStringStartsWith('https://www.bing.com/webmasters/oauth/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('webmaster.read', $q['scope']);
        $this->assertSame('code', $q['response_type']);
        $this->get('/api/v1/integrations/bing/callback?code=abc&state='.$q['state'])
            ->assertRedirect("http://app.test/businesses/{$id}/results?connected=bing");

        return IntegrationConnection::withoutGlobalScopes()->where('business_id', $id)->where('provider', 'bing_webmaster')->firstOrFail();
    }

    public function test_bing_dates_are_read_in_bings_own_day(): void
    {
        $this->assertSame('2014-05-02', BingWebmaster::date('/Date(1399014000000-0700)/'));
        $this->assertSame('2014-06-07', BingWebmaster::date('/Date(1402102800000)/'));
        $this->assertNull(BingWebmaster::date('yesterday'));
    }

    public function test_connect_picks_the_verified_matching_site_and_keeps_tokens_secret(): void
    {
        $this->fakeBing();
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = $this->connect($owner, $ws, $id);

        $this->assertSame('https://glow.example/', $c->property, 'The unverified www site must be skipped.');
        $this->assertSame('connected', $c->status);
        $this->assertNotSame('bing-refresh', DB::table('integration_connections')->value('refresh_token'));
        Queue::assertPushed(SyncBingWebmaster::class);

        $json = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/integrations")->assertOk()
            ->assertJsonPath('data.bing_webmaster.available', true)
            ->assertJsonPath('data.bing_webmaster.connection.read_only', true);
        $this->assertStringNotContainsString('bing-refresh', $json->getContent());
        $this->assertStringNotContainsString('bing-access', $json->getContent());

        $this->get('/api/v1/integrations/bing/callback?code=abc&state=reused')->assertRedirect('http://app.test/?integration_error=expired');
    }

    public function test_import_reports_totals_and_weekly_queries_without_inventing_positions(): void
    {
        $this->fakeBing();
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = $this->connect($owner, $ws, $id);
        (new SyncBingWebmaster($ws->id, $c->id))->handle(app(CurrentWorkspace::class), app(BingWebmasterImporter::class));

        $c->refresh();
        $this->assertSame(now()->subDays(2)->toDateString(), $c->data_through->toDateString());
        $this->assertSame(60, SearchPerformance::withoutGlobalScopes()->where('integration_connection_id', $c->id)->where('grain', 'total')->count());
        $this->assertSame('bing_webmaster', SearchPerformance::withoutGlobalScopes()->value('source'));

        $d = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/bing-performance?days=28")->assertOk()->json('data');
        $this->assertTrue($d['measured']);
        $this->assertSame(['clicks' => 84, 'impressions' => 1400, 'days' => 28], $d['totals']);
        $this->assertSame(['clicks' => 28, 'impressions' => 1400, 'days' => 28], $d['previous']);
        $this->assertArrayNotHasKey('position', $d['totals']);
        $top = collect($d['top_queries'])->keyBy('query');
        $this->assertSame(6, $top['makeup artist lekki']['clicks']);
        // Impression-weighted: (3*40 + 8*60) / 100 = 6.0
        $this->assertEquals(6.0, $top['makeup artist lekki']['position']);

        // Google's results stay Google-only.
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/performance")->assertJsonPath('data.connected', false);
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/report")->assertJsonPath('data.bing.clicks', fn ($v) => is_int($v));
    }

    public function test_expired_token_is_refreshed_once(): void
    {
        $this->fakeBing();
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = $this->connect($owner, $ws, $id);
        $this->expired = true;
        app(BingWebmaster::class)->traffic($c);
        $this->assertSame('bing-fresh', $c->fresh()->access_token);
        $this->assertSame('bing-refresh', $c->fresh()->refresh_token);
    }

    public function test_choosing_another_site_must_be_verified_and_disconnect_deletes_data(): void
    {
        $this->fakeBing();
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $id = $this->business($owner, $ws);
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/integrations/bing/connect")->assertForbidden();
        $c = $this->connect($owner, $ws, $id);

        $this->as($owner, $ws)->patchJson("/api/v1/businesses/{$id}/integrations/{$c->id}", ['property' => 'https://www.glow.example/'])
            ->assertUnprocessable()->assertJsonValidationErrors('property');
        $this->as($owner, $ws)->patchJson("/api/v1/businesses/{$id}/integrations/{$c->id}", ['property' => 'https://other.example/'])
            ->assertOk()->assertJsonPath('data.property', 'https://other.example/');

        app(BingWebmasterImporter::class)->sync($c->fresh());
        $this->assertGreaterThan(0, SearchPerformance::withoutGlobalScopes()->count());
        $before = count($this->calls);
        $this->as($owner, $ws)->deleteJson("/api/v1/businesses/{$id}/integrations/{$c->id}")->assertNoContent();
        $this->assertSame(0, SearchPerformance::withoutGlobalScopes()->count());
        $this->assertSame($before, count($this->calls), 'Bing has no revoke endpoint; nothing should be called.');
    }
}
