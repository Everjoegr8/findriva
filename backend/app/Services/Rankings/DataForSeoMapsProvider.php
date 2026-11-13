<?php

namespace App\Services\Rankings;

use App\Services\Crawler\UrlNormalizer;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DataForSEO Google Maps results (live, advanced) from a coordinate. Docs:
 * https://docs.dataforseo.com/v3/serp/google/maps/live/advanced/
 */
class DataForSeoMapsProvider implements MapsProvider
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

    public function estimatedCost(): float
    {
        return (float) ($this->config['estimated_cost_per_point'] ?? 0.004);
    }

    public function places(string $keyword, float $lat, float $lng, string $language, int $depth): array
    {
        try {
            $response = Http::withBasicAuth($this->config['login'], $this->config['password'])
                ->acceptJson()->timeout(60)->connectTimeout(10)
                ->post(rtrim($this->config['base_url'], '/').'/v3/serp/google/maps/live/advanced', [[
                    'keyword' => $keyword,
                    'location_coordinate' => sprintf('%.7f,%.7f,15z', $lat, $lng),
                    'language_code' => $language ?: 'en',
                    'device' => 'mobile',
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
            throw new SerpException('DataForSEO task failed: '.($task['status_message'] ?? 'unknown error'));
        }

        $places = [];
        foreach ($task['result'][0]['items'] ?? [] as $item) {
            if (($item['type'] ?? null) !== 'maps_search' || empty($item['title'])) {
                continue;
            }
            $url = isset($item['url']) && is_string($item['url']) && preg_match('#^https?://#i', $item['url']) ? $item['url'] : null;
            $places[] = [
                'position' => (int) ($item['rank_group'] ?? count($places) + 1),
                'title' => mb_substr((string) $item['title'], 0, 200),
                'domain' => $url ? UrlNormalizer::host($url) : (isset($item['domain']) ? preg_replace('/^www\./', '', strtolower((string) $item['domain'])) : null),
                'url' => $url,
                'phone' => isset($item['phone']) ? (string) $item['phone'] : null,
                'rating' => isset($item['rating']['value']) ? (float) $item['rating']['value'] : null,
                'votes' => isset($item['rating']['votes_count']) ? (int) $item['rating']['votes_count'] : null,
            ];
        }

        return ['places' => $places, 'cost' => (float) ($task['cost'] ?? $this->estimatedCost())];
    }
}
