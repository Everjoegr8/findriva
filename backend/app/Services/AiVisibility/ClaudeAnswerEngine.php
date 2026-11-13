<?php

namespace App\Services\AiVisibility;

use Anthropic\Client;
use Throwable;

/**
 * Asks Claude, with live web search, the question a customer would ask. The business is never
 * named in the prompt, so the answer shows what an assistant finds on its own.
 */
class ClaudeAnswerEngine implements AnswerEngine
{
    private const SYSTEM = <<<'TXT'
You are a helpful assistant. A person is asking you for local recommendations. Search the web, then answer the way you normally would for them: recommend specific businesses or places by name where you can, say briefly why, and keep it short. If you cannot find good options, say so.
TXT;

    private ?Client $client = null;

    public function __construct(private readonly string $apiKey, private readonly array $config) {}

    public function name(): string
    {
        return 'claude';
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function estimatedCost(): float
    {
        return (float) $this->config['estimated_cost'];
    }

    public function ask(string $question, array $location): array
    {
        $place = implode(', ', array_filter([$location['city'] ?? null, $location['country_name'] ?? $location['country']]));
        try {
            $this->client ??= new Client(apiKey: $this->apiKey);
            $message = $this->client->beta->messages->create(
                model: $this->model(),
                maxTokens: 2000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => "I'm in {$place}. {$question}"]],
                tools: [[
                    'type' => 'web_search_20260209',
                    'name' => 'web_search',
                    'maxUses' => (int) $this->config['max_searches'],
                    'userLocation' => array_filter([
                        'type' => 'approximate', 'city' => $location['city'] ?? null,
                        'country' => $location['country'], 'timezone' => $location['timezone'] ?? null,
                    ]),
                ]],
                outputConfig: ['effort' => 'low'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (Throwable $e) {
            throw new AnswerEngineException('The AI assistant is unavailable right now.', 0, $e);
        }
        if ($message->stopReason === 'refusal') {
            throw new AnswerEngineException('The AI assistant declined this question.');
        }

        $parsed = self::parse($message->content);
        $usage = $message->usage;
        $searches = (int) ($usage->serverToolUse->webSearchRequests ?? 0);
        $cost = ($usage->inputTokens * (float) $this->config['input_usd_per_mtok']
            + $usage->outputTokens * (float) $this->config['output_usd_per_mtok']) / 1_000_000
            + $searches * (float) $this->config['search_usd_each'];

        return $parsed + ['searches' => $searches, 'cost' => round($cost, 5)];
    }

    /**
     * Final answer text, plus every page the search returned, marking the ones the answer cites.
     *
     * @param  iterable<object>  $blocks
     * @return array{text: string, sources: list<array{url: string, title: string, cited: bool}>}
     */
    public static function parse(iterable $blocks): array
    {
        $text = '';
        $sources = [];
        $cited = [];
        foreach ($blocks as $b) {
            $type = $b->type ?? null;
            if ($type === 'text') {
                $text .= $b->text;
                foreach ($b->citations ?? [] as $c) {
                    if (($c->type ?? null) === 'web_search_result_location' && ! empty($c->url)) {
                        $cited[$c->url] = true;
                        $sources[$c->url] ??= ['url' => $c->url, 'title' => (string) ($c->title ?? '')];
                    }
                }
            } elseif ($type === 'web_search_tool_result' && is_array($b->content ?? null)) {
                foreach ($b->content as $r) {
                    if (! empty($r->url)) {
                        $sources[$r->url] ??= ['url' => $r->url, 'title' => (string) ($r->title ?? '')];
                    }
                }
            }
        }
        $out = [];
        foreach ($sources as $url => $s) {
            if (preg_match('#^https?://#i', $url)) {
                $out[] = $s + ['cited' => isset($cited[$url])];
            }
        }

        return ['text' => trim($text), 'sources' => $out];
    }
}
