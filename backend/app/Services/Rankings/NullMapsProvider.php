<?php

namespace App\Services\Rankings;

class NullMapsProvider implements MapsProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function estimatedCost(): float
    {
        return 0.0;
    }

    public function places(string $keyword, float $lat, float $lng, string $language, int $depth): array
    {
        throw new SerpException('No maps results provider is connected.');
    }
}
