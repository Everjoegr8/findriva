<?php

namespace App\Services\Fixes;

use App\Models\AuditIssue;
use App\Models\Business;
use App\Models\CrawledPage;
use App\Models\CrawlRun;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Facts\BusinessFacts;
use Illuminate\Support\Str;

/**
 * Ready-made fixes: files and snippets the owner (or their developer) can paste in. Nothing here
 * is pushed to the customer's site; applying a fix is always a human step, and we only mark the
 * related issue verified after a fresh audit finds it fixed.
 */
class FixGenerator
{
    public function __construct(private readonly BusinessFacts $facts) {}

    /** @return list<array<string, mixed>> */
    public function for(Business $business): array
    {
        $facts = $this->facts->for($business);
        $run = CrawlRun::query()->where('business_id', $business->id)->where('status', 'completed')->latest('id')->first();
        $openCodes = AuditIssue::query()->where('business_id', $business->id)->where('status', 'open')->pluck('code')->unique()->values()->all();
        $siteUrl = $business->website_url ? (UrlNormalizer::origin($run->site_facts['final_url'] ?? $business->website_url) ?? null) : null;

        $fixes = [
            $this->jsonLd($facts, $siteUrl, $business),
        ];
        if ($siteUrl) {
            $fixes[] = $this->robots($siteUrl, $run);
            $fixes[] = $this->sitemap($siteUrl, $run);
            $fixes[] = $this->headTags($facts, $siteUrl);
        }

        return array_map(function (array $fix) use ($openCodes) {
            $fix['open_issue_codes'] = array_values(array_intersect($fix['resolves'], $openCodes));
            $fix['missing_facts'] = array_map(fn ($k) => ['key' => $k, 'label' => BusinessFacts::label($k)], $fix['missing_facts']);

            return $fix;
        }, $fixes);
    }

    private function jsonLd(array $facts, ?string $siteUrl, Business $business): array
    {
        $data = JsonLd::localBusiness($facts, $siteUrl ? $siteUrl.'/' : null);
        $missing = array_values(array_intersect($this->facts->missing($facts), ['category', 'description', 'location', 'phone', 'hours', 'image', 'profiles']));

        return [
            'key' => 'local_business_jsonld',
            'title' => 'Structured business data (JSON-LD)',
            'summary' => 'Tells search engines and AI assistants your name, category, contact, location and hours in a format they read reliably.',
            'filename' => null,
            'format' => 'html',
            'content' => "<script type=\"application/ld+json\">\n".JsonLd::encode($data)."\n</script>",
            'instructions' => $siteUrl ? [
                'Paste this into the <head> of your homepage (WordPress: a header/footer code plugin; Wix: Settings > Custom code; Shopify: theme.liquid).',
                'Remove any older LocalBusiness or Organization markup so there is only one.',
                'Run a new audit in Findriva. The related issue is only marked fixed after we re-check your page.',
            ] : [
                'You do not have a website yet. Your Findriva public page already includes this data once you publish it.',
                'If you add a website later, paste this into its homepage <head>.',
            ],
            'schema_type' => $data['@type'],
            'resolves' => ['local_business_schema_missing', 'structured_data_invalid'],
            'missing_facts' => $missing,
            'applies_to' => $siteUrl ?? ($business->is_published ? 'public_page' : null),
        ];
    }

    private function robots(string $siteUrl, ?CrawlRun $run): array
    {
        $robots = $run?->site_facts['robots'] ?? [];
        $sitemap = $run?->site_facts['sitemap']['found'] ?? false ? ($run->site_facts['sitemap']['url'] ?? $siteUrl.'/sitemap.xml') : $siteUrl.'/sitemap.xml';
        $lines = [
            '# robots.txt for '.parse_url($siteUrl, PHP_URL_HOST).' (prepared by Findriva)',
            '# Lets every search engine and AI crawler read your public pages.',
            'User-agent: *',
            'Allow: /',
        ];
        // Keep the usual private areas private on common platforms.
        foreach (['/wp-admin/', '/cart', '/checkout', '/account'] as $private) {
            $lines[] = 'Disallow: '.$private;
        }
        if (str_contains(strtolower($robots['excerpt'] ?? ''), 'wp-admin')) {
            $lines[] = 'Allow: /wp-admin/admin-ajax.php';
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$sitemap;

        $notes = [];
        if (! empty($robots['blocks_all'])) {
            $notes[] = 'Your current robots.txt blocks all search engines. This replacement removes that block.';
        }

        return [
            'key' => 'robots_txt',
            'title' => 'robots.txt',
            'summary' => 'Tells crawlers which parts of your site they may read and where your sitemap is.',
            'filename' => 'robots.txt',
            'format' => 'text',
            'content' => implode("\n", $lines)."\n",
            'instructions' => array_merge($notes, [
                'Upload this as robots.txt at the root of your site, so it opens at '.$siteUrl.'/robots.txt.',
                'If you sell online, check the Disallow lines match your shop\'s private pages.',
                'Run a new audit in Findriva to confirm.',
            ]),
            'resolves' => ['robots_missing', 'robots_blocks_all', 'robots_error', 'sitemap_not_in_robots'],
            'missing_facts' => [],
            'applies_to' => $siteUrl,
        ];
    }

    private function sitemap(string $siteUrl, ?CrawlRun $run): array
    {
        $host = UrlNormalizer::host($siteUrl);
        $urls = collect([$siteUrl.'/']);
        if ($run) {
            $pages = CrawledPage::query()->where('crawl_run_id', $run->id)->whereNotNull('content_hash')->where('status_code', 200)->get();
            $urls = $urls->merge($pages->filter(function (CrawledPage $p) use ($host) {
                $url = $p->final_url ?? $p->url;
                $directives = strtolower(($p->robots_meta ?? '').' '.($p->x_robots_tag ?? ''));
                $self = UrlNormalizer::normalize($url);
                $canonical = $p->canonical ? UrlNormalizer::normalize($p->canonical) : null;

                return UrlNormalizer::host($url) === $host
                    && ! str_contains($directives, 'noindex')
                    && empty($p->extras['blocked_by_robots'])
                    && ($canonical === null || rtrim($canonical, '/') === rtrim((string) $self, '/'));
            })->map(fn ($p) => UrlNormalizer::normalize($p->final_url ?? $p->url)));
        }
        $urls = $urls->filter()->map(fn ($u) => rtrim($u, '/') === rtrim($siteUrl, '/') ? $siteUrl.'/' : $u)->unique()->values();

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</loc></url>\n";
        }
        $xml .= "</urlset>\n";

        return [
            'key' => 'sitemap_xml',
            'title' => 'sitemap.xml',
            'summary' => 'A list of the pages you want found, built from the pages we could read on your last audit.',
            'filename' => 'sitemap.xml',
            'format' => 'xml',
            'content' => $xml,
            'page_count' => $urls->count(),
            'instructions' => array_values(array_filter([
                $run ? null : 'Run an audit first so this includes all your pages; right now it lists only the homepage.',
                'Most site builders (WordPress with an SEO plugin, Wix, Shopify, Squarespace) make a sitemap for you. Use theirs if you have one.',
                'Otherwise upload this as sitemap.xml at the root of your site, so it opens at '.$siteUrl.'/sitemap.xml.',
                'Pages marked noindex, blocked, broken or pointing elsewhere are left out on purpose.',
            ])),
            'resolves' => ['sitemap_missing', 'sitemap_invalid', 'sitemap_urls_broken'],
            'missing_facts' => [],
            'applies_to' => $siteUrl,
        ];
    }

    private function headTags(array $facts, string $siteUrl): array
    {
        $city = $facts['primary_location']['city'] ?? null;
        $title = self::titleFor($facts['name'], $facts['category'], $city);
        $description = $facts['description'] ? Str::limit(preg_replace('/\s+/', ' ', $facts['description']), 155, '…') : null;
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $tags = [
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>'.$e($title).'</title>',
            $description ? '<meta name="description" content="'.$e($description).'">' : '<!-- Add a description of your business in Findriva to fill in the meta description. -->',
            '<link rel="canonical" href="'.$e($siteUrl.'/').'">',
            '<meta property="og:type" content="website">',
            '<meta property="og:site_name" content="'.$e($facts['name']).'">',
            '<meta property="og:title" content="'.$e($title).'">',
            $description ? '<meta property="og:description" content="'.$e($description).'">' : null,
            '<meta property="og:url" content="'.$e($siteUrl.'/').'">',
            $facts['image'] ? '<meta property="og:image" content="'.$e($facts['image']).'">' : '<!-- Add a logo or photo URL (at least 1200x630) and use it for og:image. -->',
            '<meta name="twitter:card" content="'.($facts['image'] ? 'summary_large_image' : 'summary').'">',
        ];

        return [
            'key' => 'head_tags',
            'title' => 'Homepage title, description and share preview',
            'summary' => 'What people see in search results and when your link is shared on WhatsApp, Facebook or X.',
            'filename' => null,
            'format' => 'html',
            'content' => implode("\n", array_filter($tags)),
            'instructions' => [
                'Replace the matching tags in your homepage <head>. Most site builders have fields for the title, description and social image instead.',
                'Also set lang on your <html> tag, for example <html lang="'.$e($facts['language'] ?: 'en').'">.',
                'Run a new audit in Findriva to confirm.',
            ],
            'resolves' => ['title_missing', 'title_length', 'meta_description_missing', 'open_graph_missing', 'viewport_missing', 'canonical_elsewhere'],
            'missing_facts' => array_values(array_intersect($this->facts->missing($facts), ['description', 'category', 'location', 'image'])),
            'applies_to' => $siteUrl,
        ];
    }

    /** "Name | Category in City", trimmed to fit a search result (about 60 characters). */
    public static function titleFor(string $name, ?string $category, ?string $city): string
    {
        $candidates = array_filter([
            $category && $city ? "{$name} | {$category} in {$city}" : null,
            $category ? "{$name} | {$category}" : null,
            $city ? "{$name} | {$city}" : null,
            $name,
        ]);
        foreach ($candidates as $c) {
            if (mb_strlen($c) <= 60) {
                return $c;
            }
        }

        return Str::limit($name, 60, '');
    }
}
