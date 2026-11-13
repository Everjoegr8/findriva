<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** A tiny in-memory website for crawler tests. Unknown URLs return 404. */
class FakeSite
{
    /** @var array<string, array{0: int, 1: string, 2: array}> */
    public array $pages = [];

    /** @var list<string> */
    public array $requested = [];

    public function page(string $url, string $body, int $status = 200, array $headers = ['Content-Type' => 'text/html; charset=utf-8']): static
    {
        $this->pages[$url] = [$status, $body, $headers];

        return $this;
    }

    public function redirect(string $from, string $to, int $status = 301): static
    {
        $this->pages[$from] = [$status, '', ['Location' => $to]];

        return $this;
    }

    public function text(string $url, string $body, string $type = 'text/plain'): static
    {
        return $this->page($url, $body, 200, ['Content-Type' => $type]);
    }

    public function install(): static
    {
        Http::fake(function (Request $request): PromiseInterface {
            $url = $request->url();
            $this->requested[] = $url;
            [$status, $body, $headers] = $this->pages[$url] ?? $this->pages[rtrim($url, '/')] ?? [404, 'Not found', ['Content-Type' => 'text/html']];

            return Http::response($body, $status, $headers);
        });

        return $this;
    }

    public static function html(string $head = '', string $body = '', string $lang = 'en'): string
    {
        return "<!doctype html><html lang=\"{$lang}\"><head>{$head}</head><body>{$body}</body></html>";
    }

    /** A page that passes every on-page rule. */
    public static function goodPage(string $title, string $url, string $extraHead = '', string $extraBody = ''): string
    {
        $words = str_repeat('We provide trusted bridal makeup and beauty services across Lekki and Victoria Island in Lagos. ', 20);

        return self::html(
            "<title>{$title}</title><meta name=\"description\" content=\"Description for {$title}\">"
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            ."<link rel=\"canonical\" href=\"{$url}\"><link rel=\"icon\" href=\"/favicon.ico\">"
            ."<meta property=\"og:title\" content=\"{$title}\"><meta property=\"og:image\" content=\"https://example.com/og.jpg\">"
            .$extraHead,
            "<h1>{$title}</h1><p>{$words}</p><a href=\"tel:+2348031234567\">Call +234 803 123 4567</a>".$extraBody,
            'en-NG',
        );
    }
}
