<?php

namespace App\Services\Crawler;

/**
 * Minimal robots.txt parser following RFC 9309: group matching by user-agent token,
 * longest-match wins between Allow and Disallow, '*' and '$' wildcards supported.
 */
final class RobotsTxt
{
    /** @var array<string, array{rules: list<array{type: string, path: string}>, delay: ?float}> */
    private array $groups = [];

    /** @var list<string> */
    public array $sitemaps = [];

    public function __construct(public readonly string $raw = '')
    {
        $this->parse($raw);
    }

    private function parse(string $raw): void
    {
        $currentAgents = [];
        $lastWasAgent = false;
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                $this->sitemaps[] = $value;

                continue;
            }
            if ($field === 'user-agent') {
                if (! $lastWasAgent) {
                    $currentAgents = [];
                }
                $agent = strtolower($value);
                $currentAgents[] = $agent;
                $this->groups[$agent] ??= ['rules' => [], 'delay' => null];
                $lastWasAgent = true;

                continue;
            }
            $lastWasAgent = false;
            foreach ($currentAgents as $agent) {
                if ($field === 'allow' || $field === 'disallow') {
                    if ($field === 'disallow' && $value === '') {
                        continue; // "Disallow:" with no path allows everything
                    }
                    $this->groups[$agent]['rules'][] = ['type' => $field, 'path' => $value];
                } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                    $this->groups[$agent]['delay'] = (float) $value;
                }
            }
        }
    }

    private function groupFor(string $token): ?array
    {
        $token = strtolower($token);
        foreach ($this->groups as $agent => $group) {
            if ($agent !== '*' && str_contains($token, $agent)) {
                return $group;
            }
        }

        return $this->groups['*'] ?? null;
    }

    public function isAllowed(string $url, string $token): bool
    {
        $group = $this->groupFor($token);
        if (! $group) {
            return true;
        }
        $p = parse_url($url);
        $path = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '');

        $best = null;
        $bestLen = -1;
        foreach ($group['rules'] as $rule) {
            if (self::matches($rule['path'], $path)) {
                $len = strlen($rule['path']);
                if ($len > $bestLen || ($len === $bestLen && $rule['type'] === 'allow')) {
                    $best = $rule['type'];
                    $bestLen = $len;
                }
            }
        }

        return $best !== 'disallow';
    }

    /** True when the whole site is blocked for the given agent (e.g. "Disallow: /"). */
    public function blocksEverything(string $token): bool
    {
        return ! $this->isAllowed('https://example.invalid/', $token);
    }

    public function crawlDelay(string $token): ?float
    {
        return $this->groupFor($token)['delay'] ?? null;
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = rtrim($pattern, '$');
        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).($anchored ? '$' : '').'#';

        return (bool) preg_match($regex, $path);
    }
}
