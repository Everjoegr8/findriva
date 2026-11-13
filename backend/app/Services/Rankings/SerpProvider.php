<?php

namespace App\Services\Rankings;

/** A licensed source of live search results. Implementations report what the engine showed; they never estimate. */
interface SerpProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function supportsMarket(string $market): bool;

    /** Cost (USD) we budget for one check before the provider reports the real figure. */
    public function estimatedCost(): float;

    /**
     * @return array{organic: list<array{position: int, absolute_position: ?int, domain: string, url: string, title: string}>, features: list<string>, cost: float}
     *
     * @throws SerpException
     */
    public function organic(string $keyword, string $market, string $language, string $device, int $depth): array;
}
