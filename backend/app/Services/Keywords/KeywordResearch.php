<?php

namespace App\Services\Keywords;

use App\Models\Business;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\ProviderCache;
use App\Models\UsageEvent;
use App\Services\AuditLogger;
use App\Services\Facts\BusinessFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keyword research: seeds from the business's facts, optionally measured by a provider within the
 * workspace's daily spend cap. Unmeasured phrases are stored with null metrics and labelled as such.
 */
class KeywordResearch
{
    public function __construct(
        private readonly BusinessFacts $facts,
        private readonly SeedGenerator $seeds,
        private readonly IntentClassifier $intent,
        private readonly KeywordResearchProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> summary of what happened */
    public function run(Business $business): array
    {
        $facts = $this->facts->for($business);
        $seeds = $this->seeds->generate($facts, (int) config('findriva.keywords.max_seeds', 60));
        $market = $business->country ?: 'NG';
        $language = $business->default_language ?: 'en';
        $brands = array_merge([$facts['name']], $facts['aliases']);
        $places = array_merge($facts['areas_served'], [$facts['country_name']]);

        $clusters = [];
        $clusterFor = function (string $name, string $theme) use ($business, &$clusters) {
            $key = mb_strtolower($name);
            if (! isset($clusters[$key])) {
                $clusters[$key] = KeywordCluster::query()->firstOrCreate(
                    ['business_id' => $business->id, 'name' => $name],
                    ['intent' => $theme === 'brand' ? 'navigational' : 'commercial', 'theme' => $theme],
                );
            }

            return $clusters[$key];
        };

        DB::transaction(function () use ($seeds, $business, $market, $language, $brands, $places, $clusterFor) {
            foreach ($seeds as $s) {
                $this->upsert($business, $s['keyword'], $market, [
                    'keyword_cluster_id' => $clusterFor($s['cluster'], $s['theme'])->id,
                    'intent' => $this->intent->classify($s['keyword'], $brands, $places),
                    'language' => $language,
                ], ['origin' => 'seed', 'origin_detail' => ['pattern' => $s['pattern'], 'from' => $s['cluster']]]);
            }
        });

        $summary = [
            'seeds' => count($seeds),
            'provider' => $this->provider->name(),
            'measured' => 0,
            'ideas_added' => 0,
            'spend_today_usd' => $this->spentToday($business),
            'cap_usd' => (float) ($business->workspace->entitlements()['keyword_spend_usd_per_day'] ?? 0),
        ];

        $status = $this->measure($business, $seeds, $market, $language, $brands, $places, $clusterFor, $summary);
        $summary['status'] = $status;
        $summary['message'] = match ($status) {
            'measured' => 'Search volumes measured by '.$this->provider->name().'.',
            'cached' => 'Search volumes loaded from a recent lookup (no new cost).',
            'not_configured' => 'Keyword ideas built from your services and places. Search volumes are not measured yet because no keyword data provider is connected.',
            'plan_excludes' => 'Keyword ideas built from your services and places. Your plan does not include measured search volumes.',
            'budget_exhausted' => 'Keyword ideas saved. Today\'s keyword data budget for this workspace is used up, so volumes were not measured. Try again tomorrow.',
            'unsupported_market' => 'Keyword ideas saved. Measured volumes are not available for this country yet.',
            'nothing_to_measure' => 'Add services, products or a category first so we have something to research.',
            default => 'Keyword ideas saved. The keyword data provider had a problem, so volumes were not measured. Nothing was charged.',
        };
        $summary['spend_today_usd'] = $this->spentToday($business);
        $this->audit->log('keywords.researched', $business, null, $summary);

        return $summary;
    }

    private function measure(Business $business, array $seeds, string $market, string $language, array $brands, array $places, callable $clusterFor, array &$summary): string
    {
        if (! $seeds) {
            return 'nothing_to_measure';
        }
        if (! $this->provider->isConfigured()) {
            return 'not_configured';
        }
        if ($summary['cap_usd'] <= 0) {
            return 'plan_excludes';
        }
        if ($this->provider instanceof DataForSeoKeywordProvider && ! isset(DataForSeoKeywordProvider::LOCATIONS[strtoupper($market)])) {
            return 'unsupported_market';
        }

        // Highest-value seeds first: services/products/category, not brand or question phrases.
        $chunk = collect($seeds)->sortBy(fn ($s) => in_array($s['theme'], ['brand', 'question'], true) ? 1 : 0)
            ->pluck('keyword')->take(DataForSeoKeywordProvider::MAX_SEEDS_PER_REQUEST)->sort()->values()->all();
        $hash = hash('sha256', json_encode([$this->provider->name(), 'keywords_for_keywords', $market, $language, $chunk]));

        $cached = ProviderCache::query()->where('request_hash', $hash)->where('expires_at', '>', now())->first();
        $status = 'cached';
        if ($cached) {
            $result = $cached->response;
            $retrievedAt = $cached->retrieved_at;
        } else {
            $estimate = $this->provider->estimatedCost(count($chunk));
            if ($summary['spend_today_usd'] + $estimate > $summary['cap_usd'] + 1e-9) {
                return 'budget_exhausted';
            }
            try {
                $result = $this->provider->ideas($chunk, $market, $language);
            } catch (ProviderException $e) {
                Log::warning('keyword provider failed', ['business_id' => $business->id, 'error' => $e->getMessage()]);

                return 'provider_error';
            }
            $retrievedAt = now();
            UsageEvent::create([
                'workspace_id' => $business->workspace_id, 'provider' => $this->provider->name(), 'action' => 'keyword_research',
                'units' => 1, 'estimated_cost' => $result['cost'], 'context' => ['business_id' => $business->id, 'seeds' => count($chunk)],
            ]);
            ProviderCache::query()->updateOrCreate(['request_hash' => $hash], [
                'provider' => $this->provider->name(), 'response' => $result, 'retrieved_at' => $retrievedAt,
                'expires_at' => now()->addDays((int) config('findriva.keywords.cache_days', 30)),
            ]);
            $status = 'measured';
        }

        $seedClusters = collect($seeds)->keyBy(fn ($s) => Keyword::normalize($s['keyword']));
        $ideas = collect($result['ideas'])->sortByDesc('search_volume')->take((int) config('findriva.keywords.max_ideas', 150));
        DB::transaction(function () use ($ideas, $business, $market, $language, $brands, $places, $clusterFor, $seedClusters, $retrievedAt, &$summary) {
            foreach ($ideas as $idea) {
                $normalized = Keyword::normalize($idea['keyword']);
                $seed = $seedClusters->get($normalized);
                $cluster = $seed ? $clusterFor($seed['cluster'], $seed['theme']) : $this->closestCluster($business, $normalized, $seedClusters, $clusterFor);
                $isNew = ! Keyword::query()->where('business_id', $business->id)->where('normalized', $normalized)->where('market', $market)->exists();
                $this->upsert($business, $idea['keyword'], $market, [
                    'keyword_cluster_id' => $cluster?->id,
                    'intent' => $this->intent->classify($idea['keyword'], $brands, $places),
                    'language' => $language,
                    'search_volume' => $idea['search_volume'],
                    'competition' => $idea['competition'],
                    'cpc' => $idea['cpc'],
                    'cpc_currency' => $idea['cpc_currency'],
                    'metrics_provider' => $this->provider->name(),
                    'metrics_retrieved_at' => $retrievedAt,
                ], ['origin' => $this->provider->name(), 'origin_detail' => ['method' => 'keywords_for_keywords']]);
                $summary['measured']++;
                $summary['ideas_added'] += $isNew ? 1 : 0;
            }
        });

        return $status;
    }

    /** Assign a provider idea to the seed cluster it shares the most words with. */
    private function closestCluster(Business $business, string $normalized, $seedClusters, callable $clusterFor): ?KeywordCluster
    {
        $words = array_filter(explode(' ', $normalized), fn ($w) => mb_strlen($w) > 2);
        $best = null;
        $bestScore = 0;
        foreach ($seedClusters->unique('cluster') as $seed) {
            $score = count(array_intersect($words, explode(' ', Keyword::normalize($seed['cluster']))));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$seed, $score];
            }
        }

        return $best ? $clusterFor($best['cluster'], $best['theme']) : null;
    }

    /** Insert or refresh a keyword without touching the owner's own choice (tracked/dismissed). */
    private function upsert(Business $business, string $phrase, string $market, array $values, array $onCreate): Keyword
    {
        $normalized = Keyword::normalize($phrase);
        $existing = Keyword::query()->where('business_id', $business->id)->where('normalized', $normalized)->where('market', $market)->first();
        if ($existing) {
            // Never wipe a measurement with "not measured".
            $values = array_filter($values, fn ($v, $k) => $v !== null || ! in_array($k, ['search_volume', 'competition', 'cpc', 'cpc_currency', 'metrics_provider', 'metrics_retrieved_at'], true), ARRAY_FILTER_USE_BOTH);
            $existing->update($values);

            return $existing;
        }

        return Keyword::create(['business_id' => $business->id, 'keyword' => $phrase, 'normalized' => $normalized, 'market' => $market, 'status' => 'suggested'] + $values + $onCreate);
    }

    private function spentToday(Business $business): float
    {
        return (float) UsageEvent::query()->withoutGlobalScopes()->where('workspace_id', $business->workspace_id)
            ->where('action', 'keyword_research')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost');
    }
}
