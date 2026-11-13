<?php

namespace App\Services\Fixes;

use App\Services\Facts\BusinessFacts;

/** Builds schema.org JSON-LD strictly from approved business facts. */
class JsonLd
{
    /** @return array<string, mixed> */
    public static function localBusiness(array $facts, ?string $url): array
    {
        $type = SchemaType::for($facts['category'], $facts['is_online_only']);
        $loc = $facts['primary_location'];

        $data = [
            '@context' => 'https://schema.org',
            '@type' => $type,
            'name' => $facts['name'],
        ];
        if ($url) {
            $data['@id'] = rtrim($url, '/').'/#business';
            $data['url'] = $url;
        }
        if ($facts['aliases']) {
            $data['alternateName'] = count($facts['aliases']) === 1 ? $facts['aliases'][0] : $facts['aliases'];
        }
        if ($facts['legal_name']) {
            $data['legalName'] = $facts['legal_name'];
        }
        if ($facts['description']) {
            $data['description'] = $facts['description'];
        }
        if ($facts['image']) {
            $data['image'] = $facts['image'];
        }
        $phone = $facts['phones'][0] ?? $facts['whatsapp'][0] ?? null;
        if ($phone) {
            $data['telephone'] = $phone;
        }
        if ($facts['emails']) {
            $data['email'] = $facts['emails'][0];
        }

        if ($loc && ($loc['city'] || $loc['street'])) {
            $data['address'] = array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $loc['street'],
                'addressLocality' => $loc['city'],
                'addressRegion' => $loc['region'],
                'postalCode' => $loc['postal_code'],
                'addressCountry' => $loc['country'],
            ]);
        }
        if ($loc && $loc['latitude'] !== null && $loc['longitude'] !== null) {
            $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $loc['latitude'], 'longitude' => $loc['longitude']];
        }
        if ($facts['areas_served']) {
            $data['areaServed'] = array_map(fn ($a) => ['@type' => 'Place', 'name' => $a], $facts['areas_served']);
        }
        if ($loc && $loc['hours']) {
            $data['openingHoursSpecification'] = self::hours($loc['hours']);
        }
        $sameAs = array_values(array_unique(array_merge(
            $facts['website'] && $facts['website'] !== $url ? [$facts['website']] : [],
            array_column($facts['profiles'], 'url'),
        )));
        if ($sameAs) {
            $data['sameAs'] = $sameAs;
        }

        $offers = [];
        foreach ($facts['services'] as $s) {
            $offers[] = ['@type' => 'Offer', 'itemOffered' => array_filter(['@type' => 'Service', 'name' => $s['name'], 'description' => $s['description']])];
        }
        foreach ($facts['products'] as $p) {
            $offer = ['@type' => 'Offer', 'itemOffered' => array_filter(['@type' => 'Product', 'name' => $p['name'], 'description' => $p['description']])];
            if ($p['price'] !== null && $p['currency']) {
                $offer['price'] = number_format($p['price'], 2, '.', '');
                $offer['priceCurrency'] = $p['currency'];
            }
            $offers[] = $offer;
        }
        if ($offers) {
            $data['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => 'Services and products', 'itemListElement' => array_slice($offers, 0, 50)];
        }

        return $data;
    }

    /** Safe to drop into a <script> tag: "<", ">" and "&" are escaped. */
    public static function encode(array $data, bool $pretty = true): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | ($pretty ? JSON_PRETTY_PRINT : 0));
    }

    private static function hours(array $hours): array
    {
        // Group identical opening windows across days to keep the markup short.
        $byWindow = [];
        foreach ($hours as $day => $windows) {
            foreach ($windows as [$opens, $closes]) {
                $byWindow["{$opens}-{$closes}"][] = BusinessFacts::DAYS[$day];
            }
        }
        $out = [];
        foreach ($byWindow as $window => $days) {
            [$opens, $closes] = explode('-', $window);
            $out[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $opens, 'closes' => $closes];
        }

        return $out;
    }
}
