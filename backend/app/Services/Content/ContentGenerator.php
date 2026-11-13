<?php

namespace App\Services\Content;

interface ContentGenerator
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $facts  approved facts from BusinessFacts
     * @param  array<string, mixed>  $options  e.g. ['service' => [...]]
     * @return array{title: string, body: string, source_facts: list<string>, missing_facts: list<string>}
     */
    public function generate(string $type, array $facts, array $options = []): array;
}
