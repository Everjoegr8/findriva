<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\SubmitPublicPageToIndexNow;
use App\Jobs\SyncSearchConsole;
use App\Models\DomainProperty;
use App\Models\IntegrationConnection;
use App\Models\Keyword;
use App\Models\SearchPerformance;
use App\Services\Integrations\SearchConsoleImporter;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private array $google = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'cid', 'services.google.client_secret' => 'csecret',
            'services.google.redirect' => 'http://localhost/api/v1/integrations/google/callback',
            'findriva.app_url' => 'http://app.test',
        ]);
    }

    private function business($user, $ws): int
    {
        Queue::fake();

        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://www.glow.example',
            'contacts' => [['type' => 'phone', 'value' => '08031234567']],
        ])->json('data.id');
    }

    /** Fakes Google's OAuth and Search Console APIs; records every request. */
    private function fakeGoogle(int $days = 3): void
    {
        $end = now()->subDays(3);
        Http::fake(function (Request $r) use ($days, $end) {
            $this->google[] = $r;
            $url = $r->url();
            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                return $r['grant_type'] === 'refresh_token'
                    ? Http::response(['access_token' => 'fresh-access', 'expires_in' => 3600])
                    : Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'scope' => 'openid https://www.googleapis.com/auth/webmasters.readonly email']);
            }
            if (str_starts_with($url, 'https://oauth2.googleapis.com/revoke')) {
                return Http::response([]);
            }
            if (str_contains($url, 'openidconnect')) {
                return Http::response(['email' => 'ada@gmail.example']);
            }
            if (str_ends_with($url, '/webmasters/v3/sites')) {
                return Http::response(['siteEntry' => [
                    ['siteUrl' => 'https://other.example/', 'permissionLevel' => 'siteOwner'],
                    ['siteUrl' => 'sc-domain:glow.example', 'permissionLevel' => 'siteOwner'],
                ]]);
            }
            if (str_contains($url, '/searchAnalytics/query')) {
                $dims = $r['dimensions'];
                $rows = [];
                for ($i = 0; $i < $days; $i++) {
                    $date = $end->copy()->subDays($i)->toDateString();
                    $rows[] = $dims === ['date']
                        ? ['keys' => [$date], 'clicks' => 10, 'impressions' => 200, 'ctr' => 0.05, 'position' => 8.5]
                        : ['keys' => [$date, 'bridal makeup lekki', 'https://www.glow.example/'], 'clicks' => 6, 'impressions' => 100, 'ctr' => 0.06, 'position' => 4.0];
                }

                return Http::response(['rows' => $rows]);
            }

            return Http::response('unexpected '.$url, 500);
        });
    }

    private function connect($user, $ws, int $id): IntegrationConnection
    {
        $url = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/integrations/google/connect")->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://www.googleapis.com/auth/webmasters.readonly openid email', $q['scope']);
        $this->assertSame('offline', $q['access_type']);
        $this->get('/api/v1/integrations/google/callback?code=abc&state='.$q['state'])
            ->assertRedirect("http://app.test/businesses/{$id}/results?connected=google");

        return IntegrationConnection::withoutGlobalScopes()->where('business_id', $id)->firstOrFail();
    }

    public function test_connect_google_matches_property_verifies_domain_and_keeps_tokens_secret(): void
    {
        $this->fakeGoogle();
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = $this->connect($owner, $ws, $id);

        $this->assertSame('sc-domain:glow.example', $c->property);
        $this->assertSame('connected', $c->status);
        $this->assertSame('refresh-1', $c->refresh_token);
        $raw = DB::table('integration_connections')->value('refresh_token');
        $this->assertNotSame('refresh-1', $raw, 'Tokens must be encrypted at rest.');
        Queue::assertPushed(SyncSearchConsole::class);

        $domain = DomainProperty::withoutGlobalScopes()->where('business_id', $id)->first();
        $this->assertSame('verified', $domain->verification_status);
        $this->assertSame('google_search_console', $domain->verification_method);

        $json = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/integrations")->assertOk();
        $this->assertStringNotContainsString('refresh-1', $json->getContent());
        $this->assertStringNotContainsString('access-1', $json->getContent());
        $json->assertJsonPath('data.google_search_console.connection.account_email', 'ada@gmail.example');

        // The state is single use.
        $this->get('/api/v1/integrations/google/callback?code=abc&state=reused')->assertRedirect('http://app.test/?integration_error=expired');
    }

    public function test_only_owners_and_admins_can_connect_and_editors_get_403(): void
    {
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $id = $this->business($owner, $ws);
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/integrations/google/connect")->assertForbidden();

        config(['services.google.client_id' => null]);
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/integrations/google/connect")->assertUnprocessable()->assertJsonValidationErrors('google');
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/integrations")->assertJsonPath('data.google_search_console.available', false);
    }

    public function test_declined_consent_and_missing_scope_do_not_create_a_connection(): void
    {
        $this->fakeGoogle();
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $url = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/integrations/google/connect")->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->get('/api/v1/integrations/google/callback?error=access_denied&state='.$q['state'])
            ->assertRedirect("http://app.test/businesses/{$id}/results?integration_error=declined");
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_sync_imports_finished_days_and_results_use_only_measured_data(): void
    {
        $this->fakeGoogle(days: 3);
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/performance")->assertJsonPath('data.connected', false)->assertJsonPath('data.measured', false);

        $c = $this->connect($owner, $ws, $id);
        $c->forceFill(['token_expires_at' => now()->subMinute()])->save(); // forces a refresh
        (new SyncSearchConsole($ws->id, $c->id))->handle(app(CurrentWorkspace::class), app(SearchConsoleImporter::class));

        $c->refresh();
        $this->assertSame('fresh-access', $c->access_token);
        $this->assertSame(now()->subDays(3)->toDateString(), $c->data_through->toDateString());
        $this->assertSame(3, SearchPerformance::withoutGlobalScopes()->where('grain', 'total')->count());
        $query = collect($this->google)->first(fn ($r) => str_contains($r->url(), 'searchAnalytics'));
        $this->assertSame('final', $query['dataState']);
        $this->assertStringContainsString(rawurlencode('sc-domain:glow.example'), $query->url());

        // Tracked keyword shows the position Google measured.
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/keywords", [])->assertStatus(405);
        $kw = Keyword::create(['business_id' => $id, 'keyword' => 'Bridal makeup Lekki', 'normalized' => 'bridal makeup lekki', 'intent' => 'local', 'status' => 'tracked']);

        $p = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/performance?days=7")->assertOk()->json('data');
        $this->assertTrue($p['measured']);
        $this->assertSame(30, $p['totals']['clicks']);
        $this->assertSame(600, $p['totals']['impressions']);
        $this->assertSame(8.5, $p['totals']['position']);
        $this->assertSame('bridal makeup lekki', $p['top_queries'][0]['query']);
        $this->assertEquals(['id' => $kw->id, 'keyword' => 'Bridal makeup Lekki', 'seen' => true, 'clicks' => 18, 'impressions' => 300, 'position' => 4.0], $p['tracked_keywords'][0]);

        $o = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/overview")->json('data.search_engine_data.search_console');
        $this->assertSame(30, $o['last_28_days']['clicks']);

        $report = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/report?month=".now()->subDays(3)->format('Y-m'))->assertOk()->json('data');
        $this->assertSame('Google Search Console', $report['search']['source']);
        $this->assertFalse($report['search']['comparable'], 'No previous month data, so nothing to compare.');
        $this->assertNotContains('Search clicks and impressions (connect Google Search Console)', $report['not_measured']);

        // Re-sync re-reads the last days without duplicating them.
        (new SyncSearchConsole($ws->id, $c->id))->handle(app(CurrentWorkspace::class), app(SearchConsoleImporter::class));
        $this->assertSame(3, SearchPerformance::withoutGlobalScopes()->where('grain', 'total')->count());

        // Disconnecting revokes the grant at Google and deletes imported data.
        $this->as($owner, $ws)->deleteJson("/api/v1/businesses/{$id}/integrations/{$c->id}")->assertNoContent();
        $this->assertTrue(collect($this->google)->contains(fn ($r) => str_contains($r->url(), '/revoke')));
        $this->assertSame(0, SearchPerformance::withoutGlobalScopes()->count());
    }

    public function test_revoked_grant_marks_connection_for_reconnect(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = IntegrationConnection::create(['business_id' => $id, 'provider' => 'google_search_console', 'status' => 'connected', 'property' => 'sc-domain:glow.example',
            'access_token' => 'old', 'refresh_token' => 'dead', 'token_expires_at' => now()->subHour()]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        (new SyncSearchConsole($ws->id, $c->id))->handle(app(CurrentWorkspace::class), app(SearchConsoleImporter::class));
        $this->assertSame('revoked', $c->fresh()->status);
    }

    public function test_other_tenants_cannot_touch_a_connection(): void
    {
        [$owner, $ws] = $this->member();
        [$other, $ws2] = $this->member();
        $id = $this->business($owner, $ws);
        $c = IntegrationConnection::create(['business_id' => $id, 'provider' => 'google_search_console', 'status' => 'connected']);
        $id2 = $this->business($other, $ws2);
        $this->as($other, $ws2)->deleteJson("/api/v1/businesses/{$id2}/integrations/{$c->id}")->assertNotFound();
        $this->as($other, $ws2)->getJson("/api/v1/businesses/{$id}/performance")->assertNotFound();
    }

    public function test_indexnow_requires_a_verified_key_file_and_owner_approval(): void
    {
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $id = $this->business($owner, $ws);
        $key = $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/indexnow/key")->assertOk()->json('data');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $key['key']);
        $this->assertSame('https://www.glow.example/'.$key['key'].'.txt', $key['key_location']);

        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/indexnow/submit")->assertUnprocessable()->assertJsonValidationErrors('indexnow');

        $submitted = [];
        $keyFile = 'wrong';
        Http::fake(function (Request $r) use (&$submitted, &$keyFile, $key) {
            if ($r->url() === $key['key_location']) {
                return Http::response($keyFile, 200, ['Content-Type' => 'text/plain']);
            }
            if (str_starts_with($r->url(), 'https://api.indexnow.org/')) {
                $submitted[] = $r->data();

                return Http::response('', 202);
            }

            return Http::response('', 404);
        });
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/indexnow/verify")->assertUnprocessable()->assertJsonPath('data.verified', false);
        $keyFile = $key['key']."\n";
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/indexnow/verify")->assertOk()->assertJsonPath('data.verified', true);

        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/indexnow/submit", ['urls' => ['https://www.glow.example/']])->assertForbidden();
        $res = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/indexnow/submit", ['urls' => ['https://www.glow.example/', 'https://evil.example/x']])->assertOk()->json('data');
        $this->assertSame('accepted', $res['outcome']);
        $this->assertSame(1, $res['url_count'], 'URLs on other hosts are dropped.');
        $this->assertSame('www.glow.example', $submitted[0]['host']);
        $this->assertSame($key['key'], $submitted[0]['key']);
    }

    public function test_published_pages_are_announced_with_the_platform_key_when_configured(): void
    {
        config(['findriva.indexnow.platform_key' => 'platformkey123456']);
        [$owner, $ws] = $this->member();
        Queue::fake();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Mama Put', 'primary_category' => 'Restaurant', 'description' => str_repeat('Home-style Nigerian meals in Ibadan. ', 3),
            'locations' => [['city' => 'Ibadan']], 'contacts' => [['type' => 'phone', 'value' => '08055551234']], 'services' => [['name' => 'Lunch']],
        ])->json('data.id');
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/public-page")->assertOk();
        Queue::assertPushed(SubmitPublicPageToIndexNow::class, fn ($j) => $j->url === 'http://localhost/b/mama-put-ibadan');
        $this->get('/platformkey123456.txt')->assertOk()->assertSee('platformkey123456');
        $this->get('/somethingelse99.txt')->assertNotFound();
    }
}
