<?php

namespace App\Services\AiVisibility;

class NullAnswerEngine implements AnswerEngine
{
    public function name(): string
    {
        return 'none';
    }

    public function model(): string
    {
        return '';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function estimatedCost(): float
    {
        return 0.0;
    }

    public function ask(string $question, array $location): array
    {
        throw new AnswerEngineException('No AI assistant is connected.');
    }
}
