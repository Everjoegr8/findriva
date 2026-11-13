<?php

namespace App\Services\Content;

use App\Services\Facts\BusinessFacts;
use Illuminate\Support\Str;

/**
 * Deterministic drafts built only from approved facts. Where a fact is missing we write a visible
 * [ADD: ...] placeholder instead of inventing one, and the draft cannot be approved until it is filled.
 */
class TemplateContentGenerator implements ContentGenerator
{
    private array $used = [];

    private array $missing = [];

    public function name(): string
    {
        return 'template';
    }

    public function generate(string $type, array $facts, array $options = []): array
    {
        $this->used = ['name'];
        $this->missing = [];
        $body = match ($type) {
            'business_description' => $this->description($facts),
            'google_profile_description' => $this->googleDescription($facts),
            'short_bio' => $this->bio($facts),
            'service_page' => $this->servicePage($facts, $options['service'] ?? null),
            'faq' => $this->faq($facts),
            'social_post' => $this->post($facts),
            default => throw new ContentGenerationException("Unknown content type {$type}."),
        };
        $title = $type === 'service_page' && isset($options['service']) ? $options['service']['name'] : ContentTypes::get($type)['label'];

        return ['title' => $title, 'body' => trim($body), 'source_facts' => array_values(array_unique($this->used)), 'missing_facts' => array_values(array_unique($this->missing))];
    }

    private function fact(array $facts, string $key, string $missingLabel): ?string
    {
        $v = match ($key) {
            'category' => $facts['category'] ? mb_strtolower($facts['category']) : null,
            'city' => $facts['primary_location']['city'] ?? null,
            'areas' => $facts['areas_served'] ? Str::of(collect($facts['areas_served'])->join(', ', ' and '))->toString() : null,
            'offerings' => $this->offerings($facts),
            'phone' => $facts['phones'][0] ?? $facts['whatsapp'][0] ?? null,
            'hours' => $this->hoursSummary($facts),
            default => $facts[$key] ?? null,
        };
        if ($v === null || $v === '') {
            $this->missing[] = $missingLabel;

            return '[ADD: '.BusinessFacts::label($missingLabel).']';
        }
        $this->used[] = $key;

        return $v;
    }

    private function offerings(array $facts): ?string
    {
        $names = array_merge(array_column($facts['services'], 'name'), array_column($facts['products'], 'name'));

        return $names ? collect(array_slice($names, 0, 6))->map(fn ($n) => mb_strtolower($n))->join(', ', ' and ') : null;
    }

    private function hoursSummary(array $facts): ?string
    {
        $hours = $facts['primary_location']['hours'] ?? [];
        if (! $hours) {
            return null;
        }
        $parts = [];
        foreach (BusinessFacts::DAYS as $key => $day) {
            if (isset($hours[$key])) {
                $parts[] = Str::substr($day, 0, 3).' '.collect($hours[$key])->map(fn ($w) => "{$w[0]}–{$w[1]}")->join(', ');
            }
        }

        return implode('; ', $parts);
    }

    private function description(array $facts): string
    {
        $name = $facts['name'];
        $p1 = "{$name} is a ".$this->fact($facts, 'category', 'category').' '.($facts['is_online_only'] ? 'serving customers online' : 'serving '.$this->fact($facts, 'areas', 'location')).'.';
        $p2 = 'We offer '.$this->fact($facts, 'offerings', 'offerings').'.';
        $p3 = $facts['description'] ? $facts['description'] : null;
        if ($p3) {
            $this->used[] = 'description';
        }
        $contact = 'To place an order or ask a question, call or WhatsApp us on '.$this->fact($facts, 'phone', 'phone').'.';
        $hours = $facts['primary_location']['hours'] ?? null ? 'Opening hours: '.$this->fact($facts, 'hours', 'hours').'.' : null;

        // An owner-written description already says who they are; build around it instead of repeating it.
        $intro = $p3 && mb_strlen($p3) >= 50 ? $p3.' '.$p2 : implode("\n\n", array_filter([$p1.' '.$p2, $p3]));

        return implode("\n\n", array_filter([$intro, trim($contact.' '.$hours)]));
    }

    private function googleDescription(array $facts): string
    {
        // Google's rules: no URLs, no phone numbers, no promotional claims.
        $text = "{$facts['name']} is a ".$this->fact($facts, 'category', 'category').' '
            .($facts['is_online_only'] ? 'serving customers online' : 'in '.$this->fact($facts, 'areas', 'location')).'. '
            .'We offer '.$this->fact($facts, 'offerings', 'offerings').'.';
        if ($facts['description']) {
            $this->used[] = 'description';
            $text .= ' '.preg_replace('#https?://\S+#', '', $facts['description']);
        }

        return Str::limit(trim($text), 750, '');
    }

    private function bio(array $facts): string
    {
        $cat = $facts['category'] ? $this->fact($facts, 'category', 'category') : null;
        $city = $facts['primary_location']['city'] ?? null;
        if ($city) {
            $this->used[] = 'city';
        }
        $offer = $this->offerings($facts);
        if ($offer) {
            $this->used[] = 'offerings';
        }
        $line = collect([$cat ? Str::ucfirst($cat) : null, $city ? "in {$city}" : null])->filter()->join(' ');
        $parts = array_filter([$line ?: null, $offer ? Str::ucfirst($offer) : null, ($facts['whatsapp'] || $facts['phones']) ? 'Order via WhatsApp' : null]);
        if (! $parts) {
            $this->missing[] = 'category';

            return '[ADD: '.BusinessFacts::label('category').']';
        }
        if ($facts['whatsapp'] || $facts['phones']) {
            $this->used[] = 'phone';
        }

        return Str::limit(implode(' · ', $parts), 150, '');
    }

    private function servicePage(array $facts, ?array $service): string
    {
        if (! $service) {
            throw new ContentGenerationException('Choose a service for this page.');
        }
        $this->used[] = 'services';
        $where = $facts['is_online_only'] ? 'online' : 'in '.$this->fact($facts, 'areas', 'location');
        $lines = ["# {$service['name']} {$where}", ''];
        $lines[] = "{$facts['name']} offers ".mb_strtolower($service['name'])." {$where}.";
        $lines[] = '';
        $lines[] = '## What is included';
        $lines[] = $service['description'] ?: '[ADD: what this service includes]';
        if (! $service['description']) {
            $this->missing[] = 'service_description';
        }
        $lines[] = '';
        $lines[] = '## Prices';
        $lines[] = '[ADD: your prices, or say how customers get a quote]';
        $this->missing[] = 'service_price';
        $lines[] = '';
        $lines[] = '## How to book';
        $lines[] = 'Call or WhatsApp '.$this->fact($facts, 'phone', 'phone').'.';
        if ($facts['primary_location']['hours'] ?? null) {
            $lines[] = 'We are open '.$this->fact($facts, 'hours', 'hours').'.';
        }

        return implode("\n", $lines);
    }

    private function faq(array $facts): string
    {
        $q = [];
        $q[] = ['What does '.$facts['name'].' do?', $facts['name'].' is a '.$this->fact($facts, 'category', 'category').' offering '.$this->fact($facts, 'offerings', 'offerings').'.'];
        $q[] = ['Where are you located?', $facts['is_online_only'] ? 'We serve customers online.' : (($facts['primary_location']['street'] ?? null) ? 'You can find us at '.$facts['primary_location']['street'].', '.($facts['primary_location']['city'] ?? '').'. We also serve '.$this->fact($facts, 'areas', 'location').'.' : 'We serve '.$this->fact($facts, 'areas', 'location').'.')];
        $q[] = ['What are your opening hours?', $this->fact($facts, 'hours', 'hours').'.'];
        $q[] = ['How do I order or book?', 'Call or WhatsApp us on '.$this->fact($facts, 'phone', 'phone').'.'];
        $q[] = ['How much do you charge?', '[ADD: your prices, or how customers get a quote]'];
        $this->missing[] = 'prices';

        return collect($q)->map(fn ($p) => "Q: {$p[0]}\nA: {$p[1]}")->join("\n\n");
    }

    private function post(array $facts): string
    {
        $where = $facts['is_online_only'] ? 'online' : 'in '.$this->fact($facts, 'areas', 'location');

        return 'Looking for '.$this->fact($facts, 'offerings', 'offerings')." {$where}?\n\n"
            ."{$facts['name']} is here for you. Send us a message on WhatsApp or call ".$this->fact($facts, 'phone', 'phone').' to place your order today.';
    }
}
