<?php

namespace App\Services\Crawler;

use App\Models\CrawledPage;
use App\Models\CrawlRun;

/**
 * Crawls one public website within a strict budget and records facts about it.
 * It never judges; the AuditEngine turns these facts into issues.
 */
class SiteCrawler
{
    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly HtmlAnalyzer $analyzer,
    ) {}

    /** @return array<string, mixed> site-level facts */
    public function crawl(CrawlRun $run, int $maxPages): array
    {
        $cfg = config('findriva.crawler');
        $token = $cfg['robots_token'];
        $deadline = microtime(true) + $cfg['max_runtime_seconds'];
        $maxDepth = (int) ($run->settings['max_depth'] ?? $cfg['max_depth']);

        $startUrl = UrlNormalizer::normalize($run->start_url) ?? $run->start_url;
        $home = $this->http->get($startUrl);

        $facts = [
            'start_url' => $startUrl,
            'reachable' => $home->status !== null,
            'home_status' => $home->status,
            'home_error' => $home->error,
            'final_url' => $home->finalUrl,
            'home_redirect_chain' => $home->redirectChain,
            'uses_https' => str_starts_with($home->finalUrl, 'https://'),
            'http_redirects_to_https' => null,
            'robots' => ['status' => null, 'present' => false, 'blocks_all' => false, 'sitemaps' => [], 'crawl_delay' => null],
            'sitemap' => ['found' => false, 'url' => null, 'valid' => null, 'url_count' => 0, 'error' => null, 'listed_in_robots' => false, 'sampled_broken' => []],
            'budget' => ['max_pages' => $maxPages, 'max_depth' => $maxDepth, 'stopped_reason' => null],
        ];

        if ($home->status === null) {
            return $facts;
        }

        $origin = UrlNormalizer::origin($home->finalUrl);
        $host = UrlNormalizer::host($home->finalUrl);

        if ($facts['uses_https']) {
            $httpVariant = $this->http->get('http://'.parse_url($home->finalUrl, PHP_URL_HOST).'/');
            $facts['http_redirects_to_https'] = $httpVariant->status !== null
                ? str_starts_with($httpVariant->finalUrl, 'https://')
                : null;
        }

        // robots.txt
        $robots = new RobotsTxt('');
        $robotsRes = $this->http->get($origin.'/robots.txt');
        $facts['robots']['status'] = $robotsRes->status;
        if ($robotsRes->ok() && ! str_contains(strtolower((string) $robotsRes->header('content-type')), 'html')) {
            $robots = new RobotsTxt($robotsRes->body);
            $facts['robots']['present'] = true;
            $facts['robots']['blocks_all'] = $robots->blocksEverything('googlebot') || $robots->blocksEverything($token);
            $facts['robots']['blocks_googlebot'] = $robots->blocksEverything('googlebot');
            $facts['robots']['blocks_bingbot'] = $robots->blocksEverything('bingbot');
            $facts['robots']['sitemaps'] = $robots->sitemaps;
            $facts['robots']['crawl_delay'] = $robots->crawlDelay($token);
            $facts['robots']['excerpt'] = mb_substr($robotsRes->body, 0, 2000);
        }

        // sitemap
        $sitemapCandidates = array_values(array_unique(array_merge($robots->sitemaps, [$origin.'/sitemap.xml'])));
        $sitemapUrls = [];
        foreach (array_slice($sitemapCandidates, 0, 3) as $candidate) {
            $res = $this->http->get($candidate);
            if (! $res->ok()) {
                continue;
            }
            $parsed = SitemapParser::parse($res->body);
            $facts['sitemap'] = array_merge($facts['sitemap'], [
                'found' => true, 'url' => $candidate, 'valid' => $parsed['valid'], 'error' => $parsed['error'],
                'type' => $parsed['type'], 'listed_in_robots' => in_array($candidate, $robots->sitemaps, true),
            ]);
            $sitemapUrls = $parsed['urls'];
            foreach (array_slice($parsed['sitemaps'], 0, 3) as $child) {
                $childRes = $this->http->get($child);
                if ($childRes->ok()) {
                    $childParsed = SitemapParser::parse($childRes->body);
                    $sitemapUrls = array_merge($sitemapUrls, $childParsed['urls']);
                }
            }
            $facts['sitemap']['url_count'] = count($sitemapUrls);
            if ($parsed['valid']) {
                break;
            }
        }

        $delayMs = (int) $cfg['default_delay_ms'];
        if ($facts['robots']['crawl_delay']) {
            $delayMs = (int) min($facts['robots']['crawl_delay'], $cfg['max_crawl_delay_seconds']) * 1000;
        }

        // Breadth-first crawl of same-host pages.
        $queue = [[$home->finalUrl, 0]];
        foreach ($sitemapUrls as $u) {
            $n = UrlNormalizer::normalize($u);
            if ($n && UrlNormalizer::host($n) === $host) {
                $queue[] = [$n, 1];
            }
        }
        $seen = [];
        $sitemapSet = array_flip(array_map(fn ($u) => UrlNormalizer::normalize($u) ?? $u, $sitemapUrls));
        $count = 0;
        $first = true;

        while ($queue && $count < $maxPages) {
            if (microtime(true) > $deadline) {
                $facts['budget']['stopped_reason'] = 'runtime_limit';
                break;
            }
            [$url, $depth] = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $allowed = $robots->isAllowed($url, $token);
            if (! $allowed && ! $first) {
                // Respect robots.txt: record that the page is blocked without fetching it.
                CrawledPage::create([
                    'workspace_id' => $run->workspace_id, 'crawl_run_id' => $run->id, 'url' => $url,
                    'depth' => $depth, 'indexable' => false,
                    'extras' => ['blocked_by_robots' => true, 'in_sitemap' => isset($sitemapSet[$url])],
                ]);
                $count++;

                continue;
            }

            $res = $first ? $home : $this->http->get($url);
            if (! $first && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
            $first = false;
            $count++;

            $page = $this->recordPage($run, $url, $depth, $res, ! $allowed, isset($sitemapSet[$url]));

            if ($depth < $maxDepth && $res->ok() && $res->isHtml()) {
                foreach ($page->extras['internal_links'] ?? [] as $link) {
                    if (! isset($seen[$link]) && UrlNormalizer::host($link) === $host && ! preg_match('/\.(jpe?g|png|gif|webp|svg|pdf|zip|mp4|mp3|css|js|ico|xml)$/i', parse_url($link, PHP_URL_PATH) ?? '')) {
                        $queue[] = [$link, $depth + 1];
                    }
                }
            }
        }
        if ($queue && $count >= $maxPages) {
            $facts['budget']['stopped_reason'] = 'page_limit';
        }

        // Spot-check sitemap entries that were not crawled, to catch broken sitemap URLs.
        foreach (array_slice(array_values(array_filter($sitemapUrls, fn ($u) => ! isset($seen[UrlNormalizer::normalize($u) ?? $u]))), 0, 5) as $u) {
            $r = $this->http->get($u);
            if (! $r->ok()) {
                $facts['sitemap']['sampled_broken'][] = ['url' => $u, 'status' => $r->status, 'error' => $r->error];
            }
        }

        $facts['pages_crawled'] = $count;

        return $facts;
    }

    private function recordPage(CrawlRun $run, string $url, int $depth, FetchResult $res, bool $blockedByRobots, bool $inSitemap): CrawledPage
    {
        $data = [
            'workspace_id' => $run->workspace_id,
            'crawl_run_id' => $run->id,
            'url' => $url,
            'final_url' => $res->finalUrl,
            'status_code' => $res->status,
            'depth' => $depth,
            'redirect_chain' => $res->redirectChain,
            'content_type' => $res->header('content-type'),
            'response_ms' => $res->elapsedMs,
            'x_robots_tag' => $res->header('x-robots-tag'),
        ];
        $extras = ['blocked_by_robots' => $blockedByRobots, 'in_sitemap' => $inSitemap, 'fetch_error' => $res->error, 'truncated' => $res->truncated];

        if ($res->ok() && $res->isHtml()) {
            $a = $this->analyzer->analyze($res->body, $res->finalUrl);
            $data += [
                'title' => $a['title'],
                'meta_description' => $a['meta_description'],
                'h1' => $a['h1'],
                'canonical' => $a['canonicals'][0] ?? null,
                'robots_meta' => $a['robots_meta'],
                'lang' => $a['lang'],
                'open_graph' => $a['open_graph'],
                'structured_data' => $a['structured_data'],
                'content_hash' => hash('sha256', $res->body),
            ];
            $extras += array_intersect_key($a, array_flip([
                'title_count', 'canonicals', 'viewport', 'internal_links', 'external_link_count', 'image_count',
                'images_missing_alt', 'favicon', 'hreflang', 'contact_links', 'same_as', 'word_count', 'body_text_sample',
            ]));
        }

        $noindex = str_contains(strtolower(($data['robots_meta'] ?? '').','.($data['x_robots_tag'] ?? '')), 'noindex');
        $canonical = $data['canonical'] ?? null;
        $selfCanonical = $canonical === null || UrlNormalizer::normalize($canonical) === UrlNormalizer::normalize($res->finalUrl);
        $data['indexable'] = $res->ok() && ! $noindex && ! $blockedByRobots && $selfCanonical;
        $data['extras'] = $extras;

        return CrawledPage::create($data);
    }
}
