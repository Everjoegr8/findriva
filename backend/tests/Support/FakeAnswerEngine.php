<?php

namespace Tests\Support;

use App\Services\AiVisibility\AnswerEngine;
use App\Services\AiVisibility\AnswerEngineException;

/** Stands in for a real AI assistant in tests: no network, fixed answers. */
class FakeAnswerEngine implements AnswerEngine
{
    /** @var list<array{question: string, location: array}> */
    public array $asked = [];

    public bool $fail = false;

    public function __construct(public array $answers = []) {}

    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return 'test-model';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function estimatedCost(): float
    {
        return 0.08;
    }

    public function ask(string $question, array $location): array
    {
        if ($this->fail) {
            throw new AnswerEngineException('down');
        }
        $this->asked[] = compact('question', 'location');

        return $this->answers[$question] ?? ['text' => 'I could not find good options.', 'sources' => [], 'searches' => 1, 'cost' => 0.05];
    }
}
