<?php

namespace App\Services\Rankings;

use App\Services\Crawler\UrlNormalizer;
use App\Services\Keywords\DataForSeoKeywordProvider;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DataForSEO Google organic results (live, advanced). One keyword per request. Docs:
 * https://docs.dataforseo.com/v3/serp/google/organic/live/advanced/
 */
class DataForSeoSerpProvider implements SerpProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'dataforseo';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['login']) && ! empty($this->config['password']);
    }

    public function supportsMarket(string $market): bool
    {
        return isset(DataForSeoKeywordProvider::LOCATIONS[strtoupper($market)]);
    }

    public function estimatedCost(): float
    {
        return (float) ($this->config['estimated_cost_per_check'] ?? 0.004);
    }

    public function organic(string $keyword, string $market, string $language, string $device, int $depth): array
    {
        $location = DataForSeoKeywordProvider::LOCATIONS[strtoupper($market)] ?? null;
        if ($location === null) {
            throw new SerpException("Search results are not available for market {$market} yet.");
        }

        try {
            $response = Http::withBasicAuth($this->config['login'], $this->config['password'])
                ->acceptJson()->timeout(60)->connectTimeout(10)
                ->post(rtrim($this->config['base_url'], '/').'/v3/serp/google/organic/live/advanced', [[
                    'keyword' => $keyword,
                    'location_code' => $location,
                    'language_code' => $language ?: 'en',
                    'device' => $device,
                    'depth' => $depth,
                ]]);
        } catch (Throwable $e) {
            throw new SerpException('Could not reach DataForSEO: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new SerpException('DataForSEO returned HTTP '.$response->status().'.');
        }
        $task = $response->json('tasks.0');
        if (! is_array($task) || (int) ($task['status_code'] ?? 0) !== 20000) {
            throw new SerpException('DataForSEO task failed: '.($task['status_message'] ?? $response->json('status_message') ?? 'unknown error'));
        }

        $result = $task['result'][0] ?? [];
        $organic = [];
        foreach ($result['items'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'organic' || empty($item['url']) || ! is_string($item['url'])) {
                continue;
            }
            if (! preg_match('#^https?://#i', $item['url'])) {
                continue;
            }
            $organic[] = [
                'position' => (int) ($item['rank_group'] ?? count($organic) + 1),
                'absolute_position' => isset($item['rank_absolute']) ? (int) $item['rank_absolute'] : null,
                'domain' => UrlNormalizer::host($item['url']) ?: (string) ($item['domain'] ?? ''),
                'url' => mb_substr($item['url'], 0, 2048),
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 300),
            ];
        }

        return [
            'organic' => $organic,
            'features' => array_values(array_unique(array_filter((array) ($result['item_types'] ?? []), 'is_string'))),
            'cost' => (float) ($task['cost'] ?? $response->json('cost') ?? $this->estimatedCost()),
        ];
    }
}
