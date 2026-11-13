<?php

namespace Tests\Feature;

use App\Models\CrawledPage;
use App\Models\CrawlRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSite;
use Tests\TestCase;

class WebsiteAuditTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://glow.example';

    private function onboard($user, $ws, string $site = self::SITE): array
    {
        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada',
            'primary_category' => 'Makeup artist',
            'website_url' => $site,
            'contacts' => [['type' => 'phone', 'value' => '08031234567', 'is_primary' => true]],
        ])->assertCreated()->json();
    }

    private function issues($user, $ws, int $id, string $status = 'open')
    {
        return collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/issues?status={$status}&per_page=100")->json('data'))->keyBy('code');
    }

    private function brokenSite(): FakeSite
    {
        $s = self::SITE;

        return (new FakeSite)
            ->redirect('http://glow.example/', "{$s}/")
            ->page("{$s}/", FakeSite::html(
                '<title>Home</title><script type="application/ld+json">{"@type": "LocalBusiness", broken</script>',
                '<p>Short page.</p><img src="/a.jpg"><a href="/about">About</a><a href="/services">Services</a><a href="/old">Old</a><a href="/private/x">P</a><a href="/dup">Dup</a>',
                '',
            ))
            ->page("{$s}/about", FakeSite::html('<title>About us | Glow by Ada</title><meta name="robots" content="noindex,follow">', '<h1>About</h1><h1>Again</h1>'))
            ->page("{$s}/services", FakeSite::html(
                '<title>About us | Glow by Ada</title><link rel="canonical" href="https://other-site.example/services"><link rel="canonical" href="/x">',
                '<h1>Services</h1>'
            ))
            ->page("{$s}/old", 'gone', 410)
            ->page("{$s}/dup", FakeSite::html('<title>Dup page here ok</title><link rel="canonical" href="/services">', '<h1>Dup</h1>'))
            ->text("{$s}/robots.txt", "User-agent: *\nDisallow: /private\n");
    }

    public function test_audit_finds_issues_with_evidence_and_plain_language(): void
    {
        $site = $this->brokenSite()->install();
        [$user, $ws] = $this->member();
        $biz = $this->onboard($user, $ws);
        $id = $biz['data']['id'];

        $run = CrawlRun::withoutGlobalScopes()->where('business_id', $id)->firstOrFail();
        $this->assertSame('completed', $run->status, (string) $run->error);

        $issues = $this->issues($user, $ws, $id);
        foreach ([
            'meta_description_missing', 'h1_missing', 'h1_multiple', 'title_length', 'title_duplicate',
            'noindex_pages', 'blocked_by_robots', 'page_error_status', 'canonical_multiple', 'canonical_elsewhere',
            'structured_data_invalid', 'open_graph_missing', 'sitemap_missing', 'local_business_schema_missing',
            'favicon_missing', 'viewport_missing', 'thin_content', 'images_missing_alt', 'contact_not_on_site',
            'name_not_on_site', 'phone_not_on_site',
        ] as $code) {
            $this->assertTrue($issues->has($code), "Expected issue {$code}. Got: ".$issues->keys()->implode(', '));
        }

        // noindex and robots blocking are reported separately.
        $this->assertSame('https://glow.example/about', $issues['noindex_pages']['what_we_found']['affected'][0]['url']);
        $this->assertSame('https://glow.example/private/x', $issues['blocked_by_robots']['what_we_found']['affected'][0]['url']);
        $this->assertSame(410, $issues['page_error_status']['what_we_found']['affected'][0]['status_code']);

        // Every issue explains itself and says how it will be verified.
        foreach ($issues as $i) {
            $this->assertNotEmpty($i['why_it_matters']);
            $this->assertNotEmpty($i['recommendation']);
            $this->assertNotEmpty($i['verification_method']);
        }

        // robots.txt was respected: the disallowed page was never fetched.
        $this->assertNotContains('https://glow.example/private/x', $site->requested);
        // Page text used for identity checks is not retained after the audit.
        $this->assertTrue(CrawledPage::withoutGlobalScopes()->get()->every(fn ($p) => ! isset($p->extras['body_text_sample'])));
    }

    public function test_robots_blocking_everything_is_critical(): void
    {
        $s = self::SITE;
        (new FakeSite)
            ->page("{$s}/", FakeSite::goodPage('Glow by Ada | Makeup in Lagos', "{$s}/"))
            ->text("{$s}/robots.txt", "User-agent: *\nDisallow: /\n")
            ->install();
        [$user, $ws] = $this->member();
        $id = $this->onboard($user, $ws)['data']['id'];

        $issues = $this->issues($user, $ws, $id);
        $this->assertSame('critical', $issues['robots_blocks_all']['severity']);
        $this->assertFalse($issues->has('noindex_home'), 'robots block is not the same as noindex');
    }

    public function test_redirect_to_private_network_is_blocked(): void
    {
        $this->dns->map['evil.example'] = ['93.184.216.34'];
        (new FakeSite)
            ->redirect('https://evil.example/', 'http://169.254.169.254/latest/meta-data/', 302)
            ->install();
        [$user, $ws] = $this->member();
        $id = $this->onboard($user, $ws, 'https://evil.example')['data']['id'];

        $issues = $this->issues($user, $ws, $id);
        $this->assertTrue($issues->has('site_unreachable'));
        $this->assertStringContainsString('private', $issues['site_unreachable']['what_we_found']['error']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
    }

    public function test_redirect_loops_stop_safely(): void
    {
        (new FakeSite)
            ->redirect('https://loop.example/', 'https://loop.example/a')
            ->redirect('https://loop.example/a', 'https://loop.example/')
            ->install();
        [$user, $ws] = $this->member();
        $id = $this->onboard($user, $ws, 'https://loop.example')['data']['id'];

        $this->assertStringContainsString('Too many redirects', $this->issues($user, $ws, $id)['site_unreachable']['what_we_found']['error']);
    }

    public function test_crawl_respects_page_budget(): void
    {
        $s = self::SITE;
        $links = implode('', array_map(fn ($i) => "<a href=\"/p{$i}\">p{$i}</a>", range(1, 40)));
        $site = (new FakeSite)->page("{$s}/", FakeSite::goodPage('Glow by Ada | Makeup in Lagos', "{$s}/", '', $links));
        foreach (range(1, 40) as $i) {
            $site->page("{$s}/p{$i}", FakeSite::goodPage("Glow by Ada page number {$i}", "{$s}/p{$i}"));
        }
        $site->install();

        [$user, $ws] = $this->member(plan: 'free'); // 25 pages per crawl
        $id = $this->onboard($user, $ws)['data']['id'];
        $run = CrawlRun::withoutGlobalScopes()->where('business_id', $id)->firstOrFail();

        $this->assertSame(25, $run->pages_checked);
        $this->assertSame('page_limit', $run->site_facts['budget']['stopped_reason']);
    }

    public function test_reaudit_after_fix_resolves_issues_and_verifies_actions(): void
    {
        $s = self::SITE;
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://glow.example/</loc></url></urlset>';
        $site = (new FakeSite)
            ->page("{$s}/", FakeSite::goodPage('Glow by Ada | Makeup in Lagos', "{$s}/"))
            ->text("{$s}/robots.txt", "User-agent: *\nDisallow:\nSitemap: {$s}/sitemap.xml\n")
            ->text("{$s}/sitemap.xml", $sitemap, 'application/xml')
            ->redirect('http://glow.example/', "{$s}/")
            ->install();
        [$user, $ws] = $this->member();
        $id = $this->onboard($user, $ws)['data']['id'];

        $issues = $this->issues($user, $ws, $id);
        $this->assertTrue($issues->has('local_business_schema_missing'));
        $this->assertFalse($issues->has('sitemap_missing'), $issues->keys()->implode(','));
        $this->assertFalse($issues->has('phone_not_on_site'), $issues->keys()->implode(','));
        $actionId = $issues['local_business_schema_missing']['action']['id'];

        // Owner approves; implementer marks it done.
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions/{$actionId}/request-approval")->assertOk();
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions/{$actionId}/decision", ['decision' => 'approve'])->assertOk();
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$actionId}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');

        // The site now has LocalBusiness JSON-LD.
        $jsonLd = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"BeautySalon","name":"Glow by Ada","telephone":"+2348031234567"}</script>';
        // (same fake server; Http::fake keeps the first registered handler)
        $site->page("{$s}/", FakeSite::goodPage('Glow by Ada | Makeup in Lagos', "{$s}/", $jsonLd));
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/audits")->assertStatus(202);

        $this->assertFalse($this->issues($user, $ws, $id)->has('local_business_schema_missing'));
        $action = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/actions?status=verified")->json('data'))->firstWhere('id', $actionId);
        $this->assertNotNull($action);
        $this->assertSame('The issue was no longer found on re-check.', $action['verification']['result']);
    }
}
