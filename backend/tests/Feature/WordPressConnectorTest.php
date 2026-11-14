<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AuditLog;
use App\Models\SiteConnector;
use App\Services\Publishing\PluginPackager;
use App\Services\Publishing\WordPressConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class WordPressConnectorTest extends TestCase
{
    use RefreshDatabase;

    private function business($user, $ws): int
    {
        Queue::fake();

        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://www.glow.example',
            'description' => 'Bridal and event makeup in Lekki, Lagos.',
            'contacts' => [['type' => 'phone', 'value' => '08031234567']],
        ])->json('data.id');
    }

    private function code($user, $ws, int $id): string
    {
        $code = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/pairing-code")->assertOk()->json('data.code');
        $this->assertMatchesRegularExpression('/^FDV-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        return $code;
    }

    private function pair(string $code, string $site = 'https://glow.example/'): string
    {
        // The plugin is not signed in: it only has the code the owner typed.
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/connect/wordpress/pair', ['code' => strtolower($code), 'site_url' => $site, 'plugin_version' => '1.0.0', 'wp_version' => '6.7'])
            ->assertOk()->json('data.token');
    }

    public function test_pairing_gives_the_plugin_a_token_once_and_keeps_only_its_hash(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $code = $this->code($owner, $ws, $id);
        $this->assertNull(DB::table('site_connectors')->value('token_hash'));
        $this->assertNotSame($code, DB::table('site_connectors')->value('pairing_code_hash'));

        $token = $this->pair($code);
        $this->assertSame(64, strlen($token));
        $row = DB::table('site_connectors')->first();
        $this->assertSame('connected', $row->status);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertNull($row->pairing_code_hash);
        $this->assertSame('https://glow.example', $row->site_url);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'wordpress.connected')->exists());

        // The code works once only.
        $this->postJson('/api/v1/connect/wordpress/pair', ['code' => $code, 'site_url' => 'https://glow.example/'])->assertStatus(422)->assertJsonValidationErrors('code');

        $show = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/wordpress")->assertOk()
            ->assertJsonPath('data.connector.status', 'connected')->assertJsonPath('data.connector.wp_version', '6.7')
            ->assertJsonPath('data.can_manage', true);
        $this->assertStringNotContainsString($token, $show->getContent());
        $this->assertStringNotContainsString('token_hash', $show->getContent());
    }

    public function test_a_code_only_pairs_the_businesss_own_website_and_expires(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $code = $this->code($owner, $ws, $id);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/connect/wordpress/pair', ['code' => $code, 'site_url' => 'https://someone-else.example/'])
            ->assertStatus(422)->assertJsonValidationErrors('site_url');

        $this->travel(31)->minutes();
        $this->postJson('/api/v1/connect/wordpress/pair', ['code' => $code, 'site_url' => 'https://glow.example/'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->assertSame('pending', DB::table('site_connectors')->value('status'));
    }

    public function test_nothing_is_printed_until_an_owner_or_admin_publishes(): void
    {
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $id = $this->business($owner, $ws);
        $token = $this->pair($this->code($owner, $ws, $id));

        $this->withToken($token)->getJson('/api/v1/connect/wordpress/manifest', ['X-Findriva-Plugin' => '1.0.1'])->assertOk()
            ->assertJsonPath('data.version', null)->assertJsonPath('data.homepage', []);
        $this->assertSame('1.0.1', DB::table('site_connectors')->value('plugin_version'));

        // Editors can see the connection but not publish to the customer's website or create codes.
        $this->as($editor, $ws)->getJson("/api/v1/businesses/{$id}/wordpress")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/publish", ['items' => ['jsonld']])->assertForbidden();
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/pairing-code")->assertForbidden();
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/publish", ['items' => ['sitemap']])->assertStatus(422);

        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/publish", ['items' => ['jsonld']])->assertOk()
            ->assertJsonPath('data.published_items', ['jsonld']);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'wordpress.published')->where('actor_id', $owner->id)->exists());

        $this->app['auth']->forgetGuards();
        $m = $this->withToken($token)->getJson('/api/v1/connect/wordpress/manifest')->assertOk()->json('data');
        $this->assertSame(['jsonld'], array_keys($m['homepage']), 'Only the chosen items are sent.');
        $ld = json_decode($m['homepage']['jsonld'], true);
        $this->assertSame('Glow by Ada', $ld['name']);
        $this->assertSame('https://glow.example/', $ld['url']);
        $this->assertSame(16, strlen($m['version']));

        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/publish", ['items' => ['jsonld', 'meta']])->assertOk();
        $this->app['auth']->forgetGuards();
        $meta = $this->withToken($token)->getJson('/api/v1/connect/wordpress/manifest')->json('data.homepage.meta');
        $this->assertSame('Bridal and event makeup in Lekki, Lagos.', $meta['description']);
        $this->assertSame('summary', $meta['twitter:card']);

        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/wordpress")->assertJsonPath('data.outdated', false);
        $this->as($owner, $ws)->patchJson("/api/v1/businesses/{$id}", ['description' => 'Bridal, event and editorial makeup in Lekki.'])->assertOk();
        $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/wordpress")->assertJsonPath('data.outdated', true);
        $this->app['auth']->forgetGuards();
        $this->assertSame('Bridal and event makeup in Lekki, Lagos.', $this->withToken($token)->getJson('/api/v1/connect/wordpress/manifest')->json('data.homepage.meta.description'), 'The site keeps the approved version until someone publishes again.');

        // Publishing nothing clears the site.
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/publish", ['items' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/connect/wordpress/manifest')->assertJsonPath('data.homepage', []);
    }

    public function test_unknown_tokens_and_disconnected_sites_get_nothing(): void
    {
        $this->getJson('/api/v1/connect/wordpress/manifest')->assertUnauthorized();
        $this->withToken('nope')->getJson('/api/v1/connect/wordpress/manifest')->assertUnauthorized();

        [$owner, $ws] = $this->member();
        [$other, $ws2] = $this->member();
        $id = $this->business($owner, $ws);
        $token = $this->pair($this->code($owner, $ws, $id));

        // Another workspace cannot see or remove it.
        $this->as($other, $ws2)->getJson("/api/v1/businesses/{$id}/wordpress")->assertNotFound();
        $this->as($other, $ws2)->deleteJson("/api/v1/businesses/{$id}/wordpress")->assertNotFound();

        $this->as($owner, $ws)->deleteJson("/api/v1/businesses/{$id}/wordpress")->assertNoContent();
        $this->assertSame(0, SiteConnector::withoutGlobalScopes()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/connect/wordpress/manifest')->assertUnauthorized();
    }

    public function test_a_business_needs_a_website_before_pairing(): void
    {
        [$owner, $ws] = $this->member();
        Queue::fake();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'No Site', 'primary_category' => 'Bakery'])->json('data.id');
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/wordpress/pairing-code")->assertStatus(422)->assertJsonValidationErrors('website');
    }

    public function test_the_plugin_download_is_built_from_the_reviewed_source(): void
    {
        $response = $this->get('/api/v1/connect/wordpress/plugin.zip')->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $packager = new PluginPackager;
        foreach ($packager->files() as $rel => $abs) {
            $this->assertSame(file_get_contents($abs), $zip->getFromName('findriva-connect/'.$rel), $rel);
        }
        $main = $zip->getFromName('findriva-connect/findriva-connect.php');
        $zip->close();
        $this->assertStringContainsString("define('FINDRIVA_CONNECT_VERSION', '".WordPressConnector::PLUGIN_VERSION."')", $main);
        $this->assertStringContainsString('Version:           '.WordPressConnector::PLUGIN_VERSION, $main);
    }
}
