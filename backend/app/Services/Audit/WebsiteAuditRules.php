<?php

namespace App\Services\Audit;

use App\Models\Business;
use App\Models\CrawledPage;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Fixes\SchemaType;
use App\Support\Phone;
use Illuminate\Support\Collection;

/**
 * Turns crawl facts into findings. Each finding carries the evidence that produced it,
 * so a customer (or a sceptical SEO) can see exactly why we raised it.
 */
class WebsiteAuditRules
{
    private const LOCAL_TYPES = [
        'localbusiness', 'organization', 'store', 'restaurant', 'foodestablishment', 'professionalservice',
        'beautysalon', 'hairsalon', 'healthandbeautybusiness', 'autorepair', 'automotivebusiness', 'medicalbusiness',
        'dentist', 'physician', 'legalservice', 'attorney', 'homeandconstructionbusiness', 'plumber', 'electrician',
        'lodgingbusiness', 'hotel', 'financialservice', 'educationalorganization', 'school', 'corporation',
        'onlinestore', 'onlinebusiness', 'clothingstore', 'grocerystore', 'electronicsstore', 'cafeorcoffeeshop',
        'bakery', 'barorpub', 'dayspa', 'healthclub', 'realestateagent', 'travelagency', 'pharmacy',
    ];

    /**
     * @param  array<string, mixed>  $facts
     * @param  Collection<int, CrawledPage>  $pages
     * @return list<array{code: string, url: ?string, evidence: array, crawled_page_id: ?int}>
     */
    public function evaluate(array $facts, Collection $pages, Business $business): array
    {
        $out = [];
        $add = function (string $code, ?string $url, array $evidence, ?int $pageId = null) use (&$out) {
            $out[] = ['code' => $code, 'url' => $url, 'evidence' => $evidence, 'crawled_page_id' => $pageId];
        };

        if (! $facts['reachable']) {
            $add('site_unreachable', $facts['start_url'], ['error' => $facts['home_error']]);

            return $out;
        }
        if ($facts['home_status'] >= 400) {
            $add('site_unreachable', $facts['final_url'], ['status_code' => $facts['home_status']]);

            return $out;
        }

        $origin = UrlNormalizer::origin($facts['final_url']);

        if (! $facts['uses_https']) {
            $add('https_missing', $facts['final_url'], ['final_url' => $facts['final_url']]);
        } elseif ($facts['http_redirects_to_https'] === false) {
            $add('http_not_redirecting', 'http://'.parse_url($facts['final_url'], PHP_URL_HOST).'/', ['expected' => 'redirect to https://']);
        }
        if (count($facts['home_redirect_chain']) > 2) {
            $add('redirect_chain_long', $facts['start_url'], ['chain' => $facts['home_redirect_chain']]);
        }

        // robots.txt
        $robots = $facts['robots'];
        if ($robots['present'] && $robots['blocks_all']) {
            $add('robots_blocks_all', $origin.'/robots.txt', ['excerpt' => $robots['excerpt'] ?? null,
                'blocks_googlebot' => $robots['blocks_googlebot'] ?? null, 'blocks_bingbot' => $robots['blocks_bingbot'] ?? null]);
        } elseif ($robots['status'] !== null && $robots['status'] >= 500) {
            $add('robots_error', $origin.'/robots.txt', ['status_code' => $robots['status']]);
        } elseif (! $robots['present']) {
            $add('robots_missing', $origin.'/robots.txt', ['status_code' => $robots['status']]);
        }

        // sitemap
        $sm = $facts['sitemap'];
        if (! $sm['found']) {
            $add('sitemap_missing', $origin.'/sitemap.xml', ['checked' => array_values(array_unique(array_merge($robots['sitemaps'], [$origin.'/sitemap.xml'])))]);
        } elseif ($sm['valid'] === false) {
            $add('sitemap_invalid', $sm['url'], ['error' => $sm['error']]);
        } else {
            if ($robots['present'] && ! $sm['listed_in_robots']) {
                $add('sitemap_not_in_robots', $origin.'/robots.txt', ['sitemap' => $sm['url']]);
            }
            if (! empty($sm['sampled_broken'])) {
                $add('sitemap_urls_broken', $sm['url'], ['broken' => $sm['sampled_broken']]);
            }
        }

        // ---- page level (grouped per issue code)
        $home = $pages->first(fn (CrawledPage $p) => $p->depth === 0) ?? $pages->first();
        $groups = [];
        $group = function (string $code, CrawledPage $p, array $detail = []) use (&$groups) {
            $groups[$code][] = ['page' => $p, 'detail' => $detail];
        };

        // Pages we could fetch and parse as HTML.
        $html = $pages->filter(fn (CrawledPage $p) => $p->content_hash !== null)->values();

        foreach ($pages as $p) {
            $x = $p->extras ?? [];
            if (! empty($x['blocked_by_robots'])) {
                $group('blocked_by_robots', $p, ['in_sitemap' => $x['in_sitemap'] ?? false]);

                continue;
            }
            if ($p->status_code === null || $p->status_code >= 400) {
                $group('page_error_status', $p, ['status_code' => $p->status_code, 'error' => $x['fetch_error'] ?? null]);

                continue;
            }
            if (! $html->contains($p)) {
                continue;
            }

            $robotsDirectives = strtolower(($p->robots_meta ?? '').' '.($p->x_robots_tag ?? ''));
            if (str_contains($robotsDirectives, 'noindex')) {
                $p->id === $home?->id
                    ? $add('noindex_home', $p->final_url ?? $p->url, ['robots_meta' => $p->robots_meta, 'x_robots_tag' => $p->x_robots_tag], $p->id)
                    : $group('noindex_pages', $p, ['robots_meta' => $p->robots_meta, 'x_robots_tag' => $p->x_robots_tag]);
            }

            $canonicals = $x['canonicals'] ?? [];
            if (count($canonicals) > 1) {
                $group('canonical_multiple', $p, ['canonicals' => $canonicals]);
            } elseif ($p->canonical) {
                $self = UrlNormalizer::normalize($p->final_url ?? $p->url);
                $canon = UrlNormalizer::normalize($p->canonical);
                if ($canon && UrlNormalizer::host($canon) !== UrlNormalizer::host($self ?? '')) {
                    $group('canonical_cross_domain', $p, ['canonical' => $p->canonical]);
                } elseif ($canon && rtrim($canon, '/') !== rtrim((string) $self, '/')) {
                    $group('canonical_elsewhere', $p, ['canonical' => $p->canonical]);
                }
            }

            $title = trim((string) $p->title);
            if ($title === '') {
                $group('title_missing', $p);
            } elseif (mb_strlen($title) < 15 || mb_strlen($title) > 65) {
                $group('title_length', $p, ['title' => $title, 'length' => mb_strlen($title)]);
            }
            if (trim((string) $p->meta_description) === '') {
                $group('meta_description_missing', $p);
            }
            $h1 = array_filter($p->h1 ?? [], fn ($h) => $h !== '');
            if (count($h1) === 0) {
                $group('h1_missing', $p);
            } elseif (count($h1) > 1) {
                $group('h1_multiple', $p, ['h1' => array_values($h1)]);
            }
            if (($x['word_count'] ?? 0) < 150) {
                $group('thin_content', $p, ['word_count' => $x['word_count'] ?? 0]);
            }
            if (($x['images_missing_alt'] ?? 0) > 0) {
                $group('images_missing_alt', $p, ['images_missing_alt' => $x['images_missing_alt'], 'image_count' => $x['image_count'] ?? null]);
            }
            if (! empty($p->structured_data['errors'])) {
                $group('structured_data_invalid', $p, ['errors' => $p->structured_data['errors']]);
            }
            $og = $p->open_graph ?? [];
            if (empty($og['og:title']) || empty($og['og:image'])) {
                $group('open_graph_missing', $p, ['missing' => array_values(array_filter(['og:title', 'og:description', 'og:image'], fn ($k) => empty($og[$k])))]);
            }
            if (empty($x['viewport'])) {
                $group('viewport_missing', $p);
            }
            if (empty($p->lang)) {
                $group('lang_missing', $p);
            }
            if (($p->response_ms ?? 0) > 3000) {
                $group('slow_response', $p, ['response_ms' => $p->response_ms]);
            }
        }

        // duplicates
        foreach (['title' => 'title_duplicate', 'meta_description' => 'meta_description_duplicate'] as $field => $code) {
            $dupes = $html->filter(fn ($p) => trim((string) $p->{$field}) !== '')
                ->groupBy(fn ($p) => mb_strtolower(trim($p->{$field})))
                ->filter(fn ($g) => $g->count() > 1);
            foreach ($dupes as $value => $g) {
                foreach ($g as $p) {
                    $group($code, $p, [$field => $value]);
                }
            }
        }

        foreach ($groups as $code => $items) {
            $first = $items[0]['page'];
            $add($code, $first->final_url ?? $first->url, [
                'affected_count' => count($items),
                'affected' => array_map(fn ($i) => ['url' => $i['page']->final_url ?? $i['page']->url] + $i['detail'], array_slice($items, 0, 50)),
            ], $first->id);
        }

        // ---- site-wide signals
        $types = $html->flatMap(fn ($p) => array_map('strtolower', array_merge($p->structured_data['types'] ?? [], $p->structured_data['microdata_types'] ?? [])))->unique();
        if ($types->intersect(array_merge(self::LOCAL_TYPES, SchemaType::all()))->isEmpty()) {
            $add('local_business_schema_missing', $home?->final_url ?? $facts['final_url'], ['types_found' => $types->values()->all()], $home?->id);
        }
        if ($home && empty($home->extras['favicon'])) {
            $add('favicon_missing', $home->final_url, [], $home->id);
        }

        $hasContact = $html->contains(fn ($p) => array_sum($p->extras['contact_links'] ?? []) > 0);
        if (! $hasContact && $html->isNotEmpty()) {
            $add('contact_not_on_site', $facts['final_url'], ['pages_checked' => $html->count()]);
        }

        if ($home && $home->title !== null) {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $home->title, $home->open_graph['og:site_name'] ?? null, implode(' ', $home->h1 ?? []),
            ])));
            $names = array_merge([$business->name], $business->aliases->pluck('alias')->all());
            $found = collect($names)->contains(fn ($n) => self::looseContains($haystack, $n));
            if (! $found) {
                $add('name_not_on_site', $home->final_url, ['looked_for' => $names, 'title' => $home->title, 'h1' => $home->h1], $home->id);
            }
        }

        $phones = $business->contacts->whereIn('type', ['phone', 'whatsapp'])->pluck('normalized_value')->filter()->values();
        if ($phones->isNotEmpty() && $html->isNotEmpty()) {
            $siteDigits = $html->map(fn ($p) => preg_replace('/\D+/', '', $p->extras['body_text_sample'] ?? ''))->implode('|');
            $present = $phones->contains(fn ($ph) => str_contains($siteDigits, Phone::nationalDigits($ph)));
            if (! $present) {
                $add('phone_not_on_site', $facts['final_url'], ['looked_for' => $phones->all(), 'pages_checked' => $html->count()]);
            }
        }

        return $out;
    }

    private static function looseContains(string $haystack, string $needle): bool
    {
        $norm = fn ($s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));

        return $norm($needle) !== '' && str_contains($norm($haystack), $norm($needle));
    }
}
