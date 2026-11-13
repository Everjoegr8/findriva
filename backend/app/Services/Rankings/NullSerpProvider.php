<?php

namespace App\Services\Rankings;

class NullSerpProvider implements SerpProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function supportsMarket(string $market): bool
    {
        return false;
    }

    public function estimatedCost(): float
    {
        return 0.0;
    }

    public function organic(string $keyword, string $market, string $language, string $device, int $depth): array
    {
        throw new SerpException('No search results provider is connected.');
    }
}
