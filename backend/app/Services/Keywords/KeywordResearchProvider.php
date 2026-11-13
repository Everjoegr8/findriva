<?php

namespace App\Services\Keywords;

/** A source of measured keyword data. Implementations must never estimate numbers themselves. */
interface KeywordResearchProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /** Cost (USD) we budget for one request before the provider reports the real figure. */
    public function estimatedCost(int $seedCount): float;

    /**
     * @param  list<string>  $seeds
     * @return array{ideas: list<array{keyword: string, search_volume: ?int, competition: ?float, cpc: ?float, cpc_currency: ?string}>, cost: float}
     */
    public function ideas(array $seeds, string $market, string $language): array;
}
