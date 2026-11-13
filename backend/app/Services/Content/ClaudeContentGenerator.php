<?php

namespace App\Services\Content;

use Anthropic\Client;
use Throwable;

/**
 * Optional AI drafting with Claude. The model only sees the approved facts and must leave
 * [ADD: ...] placeholders for anything missing. Every draft still goes through the claim
 * checker and human approval; nothing is published automatically.
 */
class ClaudeContentGenerator implements ContentGenerator
{
    private const SYSTEM = <<<'TXT'
You write marketing copy for small businesses, mostly in Nigeria, using only the facts you are given.

Rules you must follow:
- Use only the facts in the FACTS JSON. Do not invent prices, years in business, customer numbers, awards, certifications, guarantees, reviews, staff names, addresses, phone numbers, emails or links.
- Never promise search rankings, "number one", "best in" or similar claims.
- If a fact the copy needs is missing, write a placeholder like [ADD: opening hours] instead of guessing.
- Write in clear, warm, plain English that a customer on a phone can read quickly. Nigerian English spelling is fine.
- Output only the finished copy as plain text (simple Markdown headings are allowed for service pages). No preamble, no notes.
TXT;

    private ?Client $client = null;

    public function __construct(private readonly string $apiKey, private readonly string $model = 'claude-opus-5-5') {}

    public function name(): string
    {
        return 'claude';
    }

    public function generate(string $type, array $facts, array $options = []): array
    {
        $spec = ContentTypes::get($type);
        $service = $options['service'] ?? null;
        $prompt = "Write: {$spec['label']}".($service ? " for the service \"{$service['name']}\"" : '').".\n"
            ."Where it will be used: {$spec['use']}\n"
            ."Maximum length: {$spec['max_chars']} characters.\n\n"
            .'FACTS: '.json_encode(self::promptFacts($facts, $service), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $this->client ??= new Client(apiKey: $this->apiKey);
            $message = $this->client->beta->messages->create(
                model: $this->model,
                maxTokens: 4000,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['effort' => 'low'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (Throwable $e) {
            throw new ContentGenerationException('The AI writer is unavailable right now. Try the facts-only draft instead.', 0, $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new ContentGenerationException('The AI writer declined this request. Try the facts-only draft instead.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new ContentGenerationException('The AI writer returned an empty draft.');
        }
        preg_match_all('/\[ADD:\s*([^\]]+)\]/i', $text, $m);

        return [
            'title' => $service['name'] ?? $spec['label'],
            'body' => $text,
            'source_facts' => array_keys(array_filter(self::promptFacts($facts, $service), fn ($v) => $v !== null && $v !== [])),
            'missing_facts' => array_values(array_unique(array_map('trim', $m[1]))),
        ];
    }

    /** The minimum the model needs; no internal ids. */
    private static function promptFacts(array $facts, ?array $service): array
    {
        return [
            'name' => $facts['name'],
            'aliases' => $facts['aliases'],
            'category' => $facts['category'],
            'description' => $facts['description'],
            'areas_served' => $facts['areas_served'],
            'online_only' => $facts['is_online_only'],
            'street_address' => $facts['primary_location']['street'] ?? null,
            'opening_hours' => $facts['primary_location']['hours'] ?? [],
            'phone' => $facts['phones'][0] ?? null,
            'whatsapp' => $facts['whatsapp'][0] ?? null,
            'services' => array_map(fn ($s) => ['name' => $s['name'], 'description' => $s['description']], $facts['services']),
            'products' => array_map(fn ($p) => ['name' => $p['name'], 'description' => $p['description'], 'price' => $p['price'], 'currency' => $p['currency']], $facts['products']),
            'focus_service' => $service ? ['name' => $service['name'], 'description' => $service['description']] : null,
        ];
    }
}
