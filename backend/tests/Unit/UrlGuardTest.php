<?php

namespace Tests\Unit;

use App\Services\Crawler\UnsafeUrlException;
use App\Services\Crawler\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    public static function unsafe(): array
    {
        return [
            'localhost name' => ['http://localhost/'],
            'loopback' => ['http://127.0.0.1/'],
            'loopback alt' => ['http://127.1.2.3/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 192.168' => ['https://192.168.1.1/'],
            'private 172.16' => ['https://172.20.0.1/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'gcp metadata host' => ['http://metadata.google.internal/'],
            'cgnat' => ['http://100.64.1.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'ipv6 ula' => ['http://[fd00::1]/'],
            'zero' => ['http://0.0.0.0/'],
            'ftp scheme' => ['ftp://example.com/file'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com/'],
            'credentials' => ['https://user:pass@example.com/'],
            'odd port' => ['http://example.com:8080/'],
            'internal tld' => ['http://db.internal/'],
            'not a url' => ['not a url'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_blocks_unsafe_urls(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);
        app(UrlGuard::class)->check($url);
    }

    public function test_blocks_public_hostname_that_resolves_to_private_ip(): void
    {
        $this->dns->map['evil.example.com'] = ['93.184.216.34', '10.0.0.1'];
        $this->expectException(UnsafeUrlException::class);
        app(UrlGuard::class)->check('https://evil.example.com/');
    }

    public function test_allows_public_url_and_returns_pinned_ip(): void
    {
        $this->dns->map['shop.example.com'] = ['93.184.216.34'];
        $r = app(UrlGuard::class)->check('https://shop.example.com/products?x=1');
        $this->assertSame('93.184.216.34', $r['ip']);
        $this->assertSame(443, $r['port']);
    }

    public function test_private_override_is_ignored_in_production(): void
    {
        config(['findriva.crawler.allow_private_networks' => true]);
        $this->app['env'] = 'production';
        $this->expectException(UnsafeUrlException::class);
        app(UrlGuard::class)->check('http://127.0.0.1/');
    }
}
