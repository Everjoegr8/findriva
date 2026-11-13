<?php

namespace App\Services\Keywords;

/** Used when no provider is configured: seeds are suggested, nothing is measured. */
class NullKeywordProvider implements KeywordResearchProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function estimatedCost(int $seedCount): float
    {
        return 0.0;
    }

    public function ideas(array $seeds, string $market, string $language): array
    {
        return ['ideas' => [], 'cost' => 0.0];
    }
}
