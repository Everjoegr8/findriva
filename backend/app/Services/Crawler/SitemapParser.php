<?php

namespace App\Services\Crawler;

final class SitemapParser
{
    /** @return array{valid: bool, type: ?string, urls: list<string>, sitemaps: list<string>, error: ?string} */
    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET blocks external entity fetches; no DTD loading to avoid XXE.
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return ['valid' => false, 'type' => null, 'urls' => [], 'sitemaps' => [], 'error' => 'The sitemap is not valid XML.'];
        }

        $root = strtolower($doc->getName());
        $urls = [];
        $maps = [];
        foreach ($doc->children() as $child) {
            $loc = trim((string) ($child->loc ?? ''));
            if ($loc === '') {
                foreach ($child->children() as $grand) {
                    if ($grand->getName() === 'loc') {
                        $loc = trim((string) $grand);
                    }
                }
            }
            if ($loc === '') {
                continue;
            }
            if ($root === 'sitemapindex') {
                $maps[] = $loc;
            } else {
                $urls[] = $loc;
            }
        }

        if (! in_array($root, ['urlset', 'sitemapindex'], true)) {
            return ['valid' => false, 'type' => $root, 'urls' => [], 'sitemaps' => [], 'error' => "Unexpected root element <{$root}>."];
        }

        return ['valid' => true, 'type' => $root, 'urls' => array_slice($urls, 0, 50000), 'sitemaps' => $maps, 'error' => null];
    }
}
