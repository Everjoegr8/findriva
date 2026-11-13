<?php

namespace App\Services\Crawler;

final class UrlNormalizer
{
    /** Resolve a (possibly relative) reference against a base URL. Returns null for non-web links. */
    public static function resolve(string $base, string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || str_starts_with($ref, '#')) {
            return null;
        }
        if (preg_match('/^(mailto|tel|javascript|data|sms|whatsapp|ftp):/i', $ref)) {
            return null;
        }

        $b = parse_url($base);
        if (! $b || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }

        if (str_starts_with($ref, '//')) {
            return self::normalize($b['scheme'].':'.$ref);
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref)) {
            return self::normalize($ref);
        }

        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($ref, '/')) {
            $path = $ref;
        } elseif (str_starts_with($ref, '?')) {
            $path = ($b['path'] ?? '/').$ref;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
            $path = $dir.$ref;
        }

        return self::normalize($origin.$path);
    }

    /** Lower-case scheme/host, drop fragments and default ports, collapse dot segments. */
    public static function normalize(string $url): ?string
    {
        $p = parse_url($url);
        if (! $p || empty($p['scheme']) || empty($p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        $port = isset($p['port']) && ! (($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443)) ? ':'.$p['port'] : '';

        $segments = [];
        foreach (explode('/', $p['path'] ?? '/') as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.') {
                $segments[] = $seg;
            }
        }
        $path = implode('/', $segments);
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return $scheme.'://'.$host.$port.$path.(isset($p['query']) && $p['query'] !== '' ? '?'.$p['query'] : '');
    }

    public static function host(string $url): string
    {
        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }

    public static function origin(string $url): string
    {
        $p = parse_url($url);

        return strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port']) ? ':'.$p['port'] : '');
    }
}
