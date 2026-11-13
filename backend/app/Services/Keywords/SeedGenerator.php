<?php

namespace App\Services\Keywords;

/**
 * Builds keyword seeds only from the business's own facts: what it sells, its category and the
 * places it serves. These are phrases to test, not claims about demand.
 */
class SeedGenerator
{
    /** @return list<array{keyword: string, cluster: string, theme: string, pattern: string}> */
    public function generate(array $facts, int $max = 60): array
    {
        $places = array_slice($facts['areas_served'], 0, 4);
        $country = $facts['country_name'];
        $online = $facts['is_online_only'];
        $out = [];
        $add = function (string $kw, string $cluster, string $theme, string $pattern) use (&$out) {
            $kw = trim(preg_replace('/\s+/', ' ', mb_strtolower($kw)));
            if ($kw !== '' && mb_strlen($kw) <= 80 && ! isset($out[$kw])) {
                $out[$kw] = ['keyword' => $kw, 'cluster' => $cluster, 'theme' => $theme, 'pattern' => $pattern];
            }
        };

        $topics = [];
        foreach ($facts['services'] as $s) {
            $topics[] = [$s['name'], 'service'];
        }
        foreach ($facts['products'] as $p) {
            $topics[] = [$p['name'], 'product'];
        }
        foreach (array_filter(array_merge([$facts['category']], $facts['secondary_categories'])) as $c) {
            $topics[] = [$c, 'category'];
        }

        foreach ($topics as [$topic, $theme]) {
            $t = mb_strtolower($topic);
            $add($t, $topic, $theme, 'topic');
            if (! $online) {
                $add("{$t} near me", $topic, $theme, 'near_me');
            }
            foreach ($places as $place) {
                $add("{$t} in {$place}", $topic, $theme, 'in_place');
            }
            if ($places) {
                $add("best {$t} in {$places[0]}", $topic, $theme, 'best_in_place');
            }
            if ($theme === 'product') {
                $add("{$t} price in {$country}", $topic, $theme, 'price');
                $add("buy {$t} online", $topic, $theme, 'buy');
            } else {
                $add("{$t} cost", $topic, $theme, 'cost');
                $add("how to choose {$t}", $topic, 'question', 'question');
            }
        }

        // Brand searches: people who already know the business by name.
        $add($facts['name'], $facts['name'], 'brand', 'brand');
        if ($places) {
            $add("{$facts['name']} {$places[0]}", $facts['name'], 'brand', 'brand_place');
        }

        // Round-robin across topics so a long product list does not crowd out services.
        $byCluster = collect($out)->groupBy('cluster');
        $result = [];
        for ($i = 0; count($result) < $max && $byCluster->contains(fn ($g) => isset($g[$i])); $i++) {
            foreach ($byCluster as $g) {
                if (isset($g[$i]) && count($result) < $max) {
                    $result[] = $g[$i];
                }
            }
        }

        return $result;
    }
}
