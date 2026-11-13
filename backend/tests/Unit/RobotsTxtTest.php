<?php

namespace Tests\Unit;

use App\Services\Crawler\RobotsTxt;
use PHPUnit\Framework\TestCase;

class RobotsTxtTest extends TestCase
{
    public function test_disallow_all(): void
    {
        $r = new RobotsTxt("User-agent: *\nDisallow: /");
        $this->assertTrue($r->blocksEverything('googlebot'));
        $this->assertFalse($r->isAllowed('https://a.com/page', 'findrivabot'));
    }

    public function test_empty_disallow_allows_everything(): void
    {
        $r = new RobotsTxt("User-agent: *\nDisallow:");
        $this->assertFalse($r->blocksEverything('googlebot'));
    }

    public function test_longest_match_and_allow_override(): void
    {
        $r = new RobotsTxt("User-agent: *\nDisallow: /private\nAllow: /private/public-page\nDisallow: /*.pdf$\nSitemap: https://a.com/sitemap.xml\nCrawl-delay: 2");
        $this->assertFalse($r->isAllowed('https://a.com/private/x', 'findrivabot'));
        $this->assertTrue($r->isAllowed('https://a.com/private/public-page', 'findrivabot'));
        $this->assertFalse($r->isAllowed('https://a.com/docs/menu.pdf', 'findrivabot'));
        $this->assertTrue($r->isAllowed('https://a.com/docs/menu.pdf?v=1', 'findrivabot'));
        $this->assertSame(['https://a.com/sitemap.xml'], $r->sitemaps);
        $this->assertSame(2.0, $r->crawlDelay('findrivabot'));
    }

    public function test_specific_agent_group_wins(): void
    {
        $r = new RobotsTxt("User-agent: googlebot\nDisallow: /\n\nUser-agent: *\nDisallow:");
        $this->assertTrue($r->blocksEverything('googlebot'));
        $this->assertFalse($r->blocksEverything('bingbot'));
    }
}
