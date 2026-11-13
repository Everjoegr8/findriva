<?php

namespace App\Services\Crawler;

use DOMDocument;
use DOMXPath;

/**
 * Extracts the signals search engines and social platforms read from a page.
 * Stores facts only; judgement lives in the audit rules.
 */
class HtmlAnalyzer
{
    /** @return array<string, mixed> */
    public function analyze(string $html, string $pageUrl): array
    {
        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new DOMXPath($doc);

        $text = fn (string $q) => ($n = $xp->query($q)?->item(0)) ? trim(preg_replace('/\s+/', ' ', $n->textContent)) : null;
        $attr = fn (string $q, string $a) => ($n = $xp->query($q)?->item(0)) ? trim($n->getAttribute($a)) : null;

        $titles = $xp->query('//head/title|//title');
        $h1 = [];
        foreach ($xp->query('//h1') as $n) {
            $h1[] = mb_substr(trim(preg_replace('/\s+/', ' ', $n->textContent)), 0, 300);
        }

        $og = [];
        foreach ($xp->query('//meta[starts-with(@property,"og:") or starts-with(@name,"og:") or starts-with(@name,"twitter:")]') as $m) {
            $key = strtolower($m->getAttribute('property') ?: $m->getAttribute('name'));
            $og[$key] ??= mb_substr(trim($m->getAttribute('content')), 0, 1000);
        }

        $structured = ['blocks' => 0, 'types' => [], 'errors' => [], 'items' => []];
        foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
            $structured['blocks']++;
            $raw = trim($s->textContent);
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $structured['errors'][] = json_last_error_msg();

                continue;
            }
            foreach (self::flattenJsonLd($decoded) as $item) {
                foreach ((array) ($item['@type'] ?? []) as $t) {
                    $structured['types'][] = (string) $t;
                }
                if (count($structured['items']) < 5) {
                    $structured['items'][] = array_intersect_key($item, array_flip(['@type', 'name', 'telephone', 'address', 'url', 'geo', 'openingHours', 'sameAs']));
                }
            }
        }
        $structured['types'] = array_values(array_unique($structured['types']));
        $structured['microdata_types'] = [];
        foreach ($xp->query('//*[@itemtype]') as $n) {
            $structured['microdata_types'][] = basename($n->getAttribute('itemtype'));
        }
        $structured['microdata_types'] = array_values(array_unique($structured['microdata_types']));

        $links = [];
        $host = UrlNormalizer::host($pageUrl);
        $baseHref = $attr('//base[@href]', 'href');
        $base = $baseHref ? (UrlNormalizer::resolve($pageUrl, $baseHref) ?? $pageUrl) : $pageUrl;
        $external = 0;
        foreach ($xp->query('//a[@href]') as $a) {
            $resolved = UrlNormalizer::resolve($base, $a->getAttribute('href'));
            if (! $resolved) {
                continue;
            }
            if (UrlNormalizer::host($resolved) === $host) {
                $links[$resolved] = true;
            } else {
                $external++;
            }
        }

        $imgs = $xp->query('//img');
        $missingAlt = 0;
        foreach ($imgs as $img) {
            if (! $img->hasAttribute('alt')) {
                $missingAlt++;
            }
        }

        $tel = $xp->query('//a[starts-with(@href,"tel:")]')->length;
        $wa = $xp->query('//a[contains(@href,"wa.me") or contains(@href,"api.whatsapp.com")]')->length;
        $mail = $xp->query('//a[starts-with(@href,"mailto:")]')->length;

        $bodyText = $text('//body') ?? '';
        $canonicalNodes = $xp->query('//link[translate(@rel,"CANONICAL","canonical")="canonical"]');
        $canonicals = [];
        foreach ($canonicalNodes as $c) {
            $canonicals[] = UrlNormalizer::resolve($pageUrl, $c->getAttribute('href')) ?? $c->getAttribute('href');
        }

        $iconHref = $attr('//link[contains(translate(@rel,"ICON","icon"),"icon")]', 'href');

        $sameAs = [];
        foreach ($structured['items'] as $item) {
            foreach ((array) ($item['sameAs'] ?? []) as $s) {
                $sameAs[] = $s;
            }
        }

        return [
            'title' => $titles->length ? mb_substr(trim(preg_replace('/\s+/', ' ', $titles->item(0)->textContent)), 0, 500) : null,
            'title_count' => $titles->length,
            'meta_description' => ($d = $attr('//meta[translate(@name,"DESCRIPTION","description")="description"]', 'content')) !== null ? mb_substr($d, 0, 1000) : null,
            'h1' => $h1,
            'canonicals' => array_values(array_unique($canonicals)),
            'robots_meta' => $attr('//meta[translate(@name,"ROBOTS","robots")="robots"]', 'content'),
            'lang' => $attr('//html', 'lang') ?: null,
            'viewport' => $attr('//meta[@name="viewport"]', 'content'),
            'open_graph' => $og,
            'structured_data' => $structured,
            'internal_links' => array_slice(array_keys($links), 0, 500),
            'external_link_count' => $external,
            'image_count' => $imgs->length,
            'images_missing_alt' => $missingAlt,
            'favicon' => $iconHref ? UrlNormalizer::resolve($pageUrl, $iconHref) : null,
            'hreflang' => array_map(fn ($n) => $n->getAttribute('hreflang'), iterator_to_array($xp->query('//link[@hreflang]'))),
            'contact_links' => ['tel' => $tel, 'whatsapp' => $wa, 'mailto' => $mail],
            'same_as' => array_values(array_unique($sameAs)),
            'word_count' => str_word_count(strip_tags($bodyText)),
            'body_text_sample' => mb_substr($bodyText, 0, 5000),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function flattenJsonLd(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return array_merge(...array_map(fn ($d) => self::flattenJsonLd($d), $data['@graph'])) ?: [];
        }
        if (array_is_list($data)) {
            return array_merge([], ...array_map(fn ($d) => self::flattenJsonLd($d), $data));
        }

        return [$data];
    }
}
