<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The only way the platform fetches third-party URLs. Redirects are followed manually so
 * each hop is re-validated by UrlGuard, the connection is pinned to the validated IP, and
 * response size and time are capped.
 */
class SafeHttpClient
{
    public function __construct(private readonly UrlGuard $guard) {}

    public function get(string $url): FetchResult
    {
        $cfg = config('findriva.crawler');
        $maxBytes = (int) $cfg['max_body_bytes'];
        $chain = [];
        $current = $url;
        $start = microtime(true);

        for ($hop = 0; $hop <= $cfg['max_redirects']; $hop++) {
            try {
                $target = $this->guard->check($current);
            } catch (UnsafeUrlException $e) {
                return $this->failure($url, $current, $chain, $start, $e->getMessage());
            }

            $resolveIp = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];

            try {
                $response = Http::withHeaders([
                    'User-Agent' => $cfg['user_agent'],
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
                ])
                    ->timeout($cfg['timeout_seconds'])
                    ->connectTimeout($cfg['connect_timeout_seconds'])
                    ->withOptions([
                        'allow_redirects' => false,
                        'http_errors' => false,
                        'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$resolveIp}"]],
                        'progress' => function ($downloadTotal, $downloaded) use ($maxBytes) {
                            if ($downloaded > $maxBytes) {
                                throw new \RuntimeException('Response exceeded size limit.');
                            }
                        },
                    ])
                    ->get($current);
            } catch (Throwable $e) {
                $msg = str_contains($e->getMessage(), 'size limit') ? 'The page is larger than the audit size limit.' : 'The site could not be reached: '.class_basename($e);

                return $this->failure($url, $current, $chain, $start, $msg);
            }

            $status = $response->status();
            $headers = [];
            foreach ($response->headers() as $name => $values) {
                $headers[strtolower($name)] = implode(', ', (array) $values);
            }

            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                $chain[] = ['url' => $current, 'status' => $status];
                $current = UrlNormalizer::resolve($current, $headers['location']);
                if ($current === null) {
                    return $this->failure($url, $url, $chain, $start, 'The site sent an invalid redirect.');
                }

                continue;
            }

            $body = $response->body();
            $truncated = strlen($body) > $maxBytes;

            return new FetchResult(
                requestedUrl: $url,
                finalUrl: $current,
                status: $status,
                headers: $headers,
                body: $truncated ? substr($body, 0, $maxBytes) : $body,
                redirectChain: $chain,
                elapsedMs: (int) round((microtime(true) - $start) * 1000),
                truncated: $truncated,
            );
        }

        return $this->failure($url, $current, $chain, $start, 'Too many redirects (more than '.$cfg['max_redirects'].').');
    }

    /** @param list<array{url: string, status: int}> $chain */
    private function failure(string $url, string $final, array $chain, float $start, string $error): FetchResult
    {
        return new FetchResult($url, $final, null, [], '', $chain, (int) round((microtime(true) - $start) * 1000), false, $error);
    }
}
