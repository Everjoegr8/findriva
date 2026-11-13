<?php

namespace App\Services\Rankings;

/** Licensed Google Maps results as seen from one map point. */
interface MapsProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    public function estimatedCost(): float;

    /**
     * @return array{places: list<array{position: int, title: string, domain: ?string, url: ?string, phone: ?string, rating: ?float, votes: ?int}>, cost: float}
     *
     * @throws SerpException
     */
    public function places(string $keyword, float $lat, float $lng, string $language, int $depth): array;
}
