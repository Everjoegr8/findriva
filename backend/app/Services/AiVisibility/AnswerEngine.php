<?php

namespace App\Services\AiVisibility;

/** An AI assistant that answers a question with live web search and reports its sources. */
interface AnswerEngine
{
    public function name(): string;

    public function model(): string;

    public function isConfigured(): bool;

    /** USD budgeted for one answer before the real usage is known. */
    public function estimatedCost(): float;

    /**
     * @param  array{city: ?string, country: string, timezone: ?string}  $location
     * @return array{text: string, sources: list<array{url: string, title: string, cited: bool}>, searches: int, cost: float}
     *
     * @throws AnswerEngineException
     */
    public function ask(string $question, array $location): array;
}
