<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\SearchPerformance;
use App\Services\Crawler\UrlNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Imports Bing Webmaster Tools data. Bing returns its whole window (about six months) on every
 * call, so each import replaces what we hold for the connection.
 *   grain "total":       one row per day for the whole site (clicks, impressions; no position)
 *   grain "query_week":  one row per query per week, with Bing's average impression position
 */
class BingWebmasterImporter
{
    public function __construct(private readonly BingWebmaster $bing) {}

    /** The verified Bing site that matches the business website. */
    public function matchSite(array $sites, ?string $website): ?string
    {
        $verified = array_values(array_filter($sites, fn ($s) => $s['verified']));
        $host = $website ? preg_replace('/^www\./', '', (string) UrlNormalizer::host($website)) : null;
        if (! $host) {
            return count($verified) === 1 ? $verified[0]['site_url'] : null;
        }
        foreach ($verified as $s) {
            if (preg_replace('/^www\./', '', (string) UrlNormalizer::host($s['site_url'])) === $host) {
                return $s['site_url'];
            }
        }

        return null;
    }

    /** @return array{days: int, query_rows: int, through: ?string} */
    public function sync(IntegrationConnection $c): array
    {
        $traffic = $this->bing->traffic($c);
        $queries = array_slice($this->bing->queries($c), 0, (int) config('findriva.bing_webmaster.max_query_rows'));

        DB::transaction(function () use ($c, $traffic, $queries) {
            SearchPerformance::query()->where('integration_connection_id', $c->id)->delete();
            $now = now();
            $rows = [];
            foreach ($traffic as $t) {
                $rows[$this->hash('total', $t['date'], null)] = $this->row($c, 'total', $t['date'], null, $t['clicks'], $t['impressions'], 0, $now);
            }
            foreach ($queries as $q) {
                // Bing can list the same query twice in a week; keep one row per query and week.
                $rows[$this->hash('query_week', $q['date'], $q['query'])] = $this->row($c, 'query_week', $q['date'], $q['query'], $q['clicks'], $q['impressions'], $q['position'], $now);
            }
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                SearchPerformance::query()->insert($chunk);
            }
        });

        $through = collect($traffic)->max('date');
        $c->update(['last_synced_at' => now(), 'data_through' => $through, 'last_error' => null, 'status' => 'connected']);

        return ['days' => count($traffic), 'query_rows' => count($queries), 'through' => $through];
    }

    private function hash(string $grain, string $date, ?string $query): string
    {
        return hash('sha256', $grain."\0".$date."\0".$query);
    }

    private function row(IntegrationConnection $c, string $grain, string $date, ?string $query, int $clicks, int $impressions, float $position, $now): array
    {
        return [
            'workspace_id' => $c->workspace_id, 'business_id' => $c->business_id, 'integration_connection_id' => $c->id,
            'source' => 'bing_webmaster', 'date' => $date, 'grain' => $grain, 'query' => $query, 'page' => null,
            'row_hash' => $this->hash($grain, $date, $query),
            'clicks' => max(0, $clicks), 'impressions' => max(0, $impressions),
            'ctr' => $impressions > 0 ? round($clicks / $impressions, 6) : 0, 'position' => round($position, 2),
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
}
