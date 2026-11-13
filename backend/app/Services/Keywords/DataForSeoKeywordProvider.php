<?php

namespace App\Services\Keywords;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DataForSEO Google Ads "keywords for keywords" (live). Returns related keyword ideas with
 * Google Ads search volume, competition and CPC for the market. Docs:
 * https://docs.dataforseo.com/v3/keywords_data/google_ads/keywords_for_keywords/live/
 */
class DataForSeoKeywordProvider implements KeywordResearchProvider
{
    /** Google Ads location codes for the markets we serve. */
    public const LOCATIONS = ['NG' => 2566, 'GH' => 2288, 'KE' => 2404, 'ZA' => 2710, 'US' => 2840, 'GB' => 2826, 'CA' => 2124];

    public const MAX_SEEDS_PER_REQUEST = 20;

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'dataforseo';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['login']) && ! empty($this->config['password']);
    }

    public function estimatedCost(int $seedCount): float
    {
        return (float) ($this->config['estimated_cost_per_request'] ?? 0.075);
    }

    public function ideas(array $seeds, string $market, string $language): array
    {
        $location = self::LOCATIONS[strtoupper($market)] ?? null;
        if ($location === null) {
            throw new ProviderException("Keyword data is not available for market {$market} yet.");
        }

        try {
            $response = Http::withBasicAuth($this->config['login'], $this->config['password'])
                ->acceptJson()->timeout(60)->connectTimeout(10)
                ->post(rtrim($this->config['base_url'], '/').'/v3/keywords_data/google_ads/keywords_for_keywords/live', [[
                    'keywords' => array_values(array_slice($seeds, 0, self::MAX_SEEDS_PER_REQUEST)),
                    'location_code' => $location,
                    'language_code' => $language ?: 'en',
                    'sort_by' => 'search_volume',
                ]]);
        } catch (Throwable $e) {
            throw new ProviderException('Could not reach DataForSEO: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new ProviderException('DataForSEO returned HTTP '.$response->status().'.');
        }
        $task = $response->json('tasks.0');
        if (! is_array($task) || (int) ($task['status_code'] ?? 0) !== 20000) {
            throw new ProviderException('DataForSEO task failed: '.($task['status_message'] ?? $response->json('status_message') ?? 'unknown error'));
        }

        $ideas = [];
        foreach ($task['result'] ?? [] as $row) {
            if (empty($row['keyword']) || ! is_string($row['keyword'])) {
                continue;
            }
            $ideas[] = [
                'keyword' => $row['keyword'],
                'search_volume' => isset($row['search_volume']) ? (int) $row['search_volume'] : null,
                'competition' => isset($row['competition_index']) ? round(((float) $row['competition_index']) / 100, 4) : null,
                'cpc' => isset($row['cpc']) ? (float) $row['cpc'] : null,
                // Google Ads bids come back in USD from this endpoint.
                'cpc_currency' => isset($row['cpc']) ? 'USD' : null,
            ];
        }

        return ['ideas' => $ideas, 'cost' => (float) ($task['cost'] ?? $response->json('cost') ?? $this->estimatedCost(count($seeds)))];
    }
}
