<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSite;
use Tests\TestCase;

class AssetScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_scan_reads_preview_and_flags_unreachable_and_name_mismatch(): void
    {
        (new FakeSite)
            ->page('https://www.instagram.com/glowbyada', FakeSite::html('<meta property="og:title" content="Glow by Ada (@glowbyada)"><meta property="og:site_name" content="Instagram">'))
            ->page('https://www.facebook.com/glowwrong', 'nope', 404)
            ->page('https://glowbyada.myshopify.com/', FakeSite::html('<meta property="og:site_name" content="Totally Different Shop"><meta property="og:title" content="Shop">'))
            ->install();

        [$user, $ws] = $this->member();
        $res = $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada',
            'aliases' => [['alias' => 'Instagram']], // platform names are not conflicts when aliased
            'assets' => [
                ['url' => 'https://www.instagram.com/glowbyada'],
                ['url' => 'https://www.facebook.com/glowwrong'],
                ['url' => 'https://glowbyada.myshopify.com/'],
            ],
        ])->assertCreated();
        $id = $res->json('data.id');

        $assets = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/assets")->json('data'))->keyBy('platform');
        $this->assertSame('ok', $assets['instagram']['scan_status']);
        $this->assertSame('public_page_fetch', $assets['instagram']['scan_data']['source']);
        $this->assertSame('unreachable', $assets['facebook']['scan_status']);

        $issues = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/issues")->json('data'))->keyBy('code');
        $this->assertTrue($issues->has('asset_unreachable'));
        $conflicts = collect($issues['identity_conflict']['what_we_found']['conflicts']);
        $this->assertTrue($conflicts->contains(fn ($c) => $c['field'] === 'name' && in_array('Totally Different Shop', $c['values'], true)));
    }

    public function test_connected_state_cannot_be_faked_via_api(): void
    {
        (new FakeSite)->install();
        [$user, $ws] = $this->member();
        $res = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'A', 'assets' => [['url' => 'https://tiktok.com/@a']]]);
        $assetId = $res->json('data.assets.0.id');
        $this->as($user, $ws)->patchJson('/api/v1/businesses/'.$res->json('data.id')."/assets/{$assetId}", ['connection_state' => 'connected'])->assertUnprocessable();
    }
}
