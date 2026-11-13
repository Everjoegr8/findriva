<?php

namespace App\Services\Crawler;

/**
 * SSRF protection. Every URL the platform fetches, including every redirect hop, passes
 * through here. A URL is allowed only if it is http(s), on an allowed port, has no
 * credentials, and every address its host resolves to is publicly routable.
 * The validated IP is returned so the HTTP client can pin to it (prevents DNS rebinding).
 */
class UrlGuard
{
    /** Ranges PHP's FILTER_FLAG_GLOBAL_RANGE may not cover on every build. */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    private const BLOCKED_HOSTNAMES = ['localhost', 'metadata.google.internal', 'metadata'];

    public function __construct(private readonly HostResolver $resolver) {}

    /** @return array{url: string, scheme: string, host: string, port: int, ip: string} */
    public function check(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new UnsafeUrlException('The address is not a valid web URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http and https addresses can be checked.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('Addresses containing credentials are not allowed.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        $host = trim($host, '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        if (! in_array($port, config('findriva.crawler.allowed_ports'), true)) {
            throw new UnsafeUrlException("Port {$port} is not allowed.");
        }

        $allowPrivate = $this->allowPrivate();

        if (! $allowPrivate && (in_array($host, self::BLOCKED_HOSTNAMES, true)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local'))) {
            throw new UnsafeUrlException('Internal hostnames are not allowed.');
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrlException("The domain {$host} could not be found (DNS lookup failed).");
        }

        if (! $allowPrivate) {
            foreach ($ips as $ip) {
                if (! self::isPublicIp($ip)) {
                    throw new UnsafeUrlException('The address points to a private or reserved network.');
                }
            }
        }

        return ['url' => $url, 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (defined('FILTER_FLAG_GLOBAL_RANGE') && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subBin = @inet_pton($subnet);
        if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);

        return ($ipBin[$bytes] & $mask) === ($subBin[$bytes] & $mask);
    }

    private function allowPrivate(): bool
    {
        return config('findriva.crawler.allow_private_networks') && ! app()->environment('production');
    }
}
