<?php

namespace App\Services\Crawler;

final class FetchResult
{
    /**
     * @param  array<string, string>  $headers  lower-cased header names
     * @param  list<array{url: string, status: int}>  $redirectChain
     */
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly ?int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly array $redirectChain,
        public readonly int $elapsedMs,
        public readonly bool $truncated = false,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->error === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isHtml(): bool
    {
        $type = strtolower((string) $this->header('content-type'));

        return $type === '' || str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
    }
}
