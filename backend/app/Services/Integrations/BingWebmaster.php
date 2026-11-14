<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bing Webmaster Tools with the read-only scope. The customer signs in on Bing's own page
 * (Microsoft, Google or Facebook account); we never see their password.
 */
class BingWebmaster
{
    private const AUTH_URL = 'https://www.bing.com/webmasters/oauth/authorize';

    private const TOKEN_URL = 'https://www.bing.com/webmasters/oauth/token';

    private const API = 'https://ssl.bing.com/webmaster/api.svc/json';

    public function isConfigured(): bool
    {
        return filled(config('services.bing_webmaster.client_id')) && filled(config('services.bing_webmaster.client_secret'));
    }

    public function scope(): string
    {
        return (string) config('findriva.bing_webmaster.scope');
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.bing_webmaster.client_id'),
            'redirect_uri' => config('services.bing_webmaster.redirect'),
            'scope' => $this->scope(),
            'state' => $state,
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int} */
    public function exchangeCode(string $code): array
    {
        $r = $this->send(fn () => Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.bing_webmaster.client_id'),
            'client_secret' => config('services.bing_webmaster.client_secret'),
            'redirect_uri' => config('services.bing_webmaster.redirect'),
            'grant_type' => 'authorization_code',
        ]));
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new IntegrationException('Bing did not accept the sign-in. Please try connecting again.');
        }

        return $r->json();
    }

    /** @return list<array{site_url: string, verified: bool}> */
    public function sites(IntegrationConnection $c): array
    {
        $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(20)->get(self::API.'/GetUserSites'));

        return collect($r->json('d') ?? [])
            ->map(fn ($s) => ['site_url' => (string) ($s['Url'] ?? ''), 'verified' => (bool) ($s['IsVerified'] ?? false)])
            ->filter(fn ($s) => $s['site_url'] !== '')->values()->all();
    }

    /** Daily clicks and impressions for the whole site. @return list<array{date: string, clicks: int, impressions: int}> */
    public function traffic(IntegrationConnection $c): array
    {
        return collect($this->get($c, 'GetRankAndTrafficStats'))
            ->map(fn ($r) => ['date' => self::date((string) ($r['Date'] ?? '')), 'clicks' => (int) ($r['Clicks'] ?? 0), 'impressions' => (int) ($r['Impressions'] ?? 0)])
            ->filter(fn ($r) => $r['date'] !== null)->values()->all();
    }

    /** Weekly figures per search query. @return list<array{date: string, query: string, clicks: int, impressions: int, position: float}> */
    public function queries(IntegrationConnection $c): array
    {
        return collect($this->get($c, 'GetQueryStats'))
            ->map(fn ($r) => [
                'date' => self::date((string) ($r['Date'] ?? '')), 'query' => (string) ($r['Query'] ?? ''),
                'clicks' => (int) ($r['Clicks'] ?? 0), 'impressions' => (int) ($r['Impressions'] ?? 0),
                'position' => (float) ($r['AvgImpressionPosition'] ?? 0),
            ])
            ->filter(fn ($r) => $r['date'] !== null && $r['query'] !== '')->values()->all();
    }

    /** Bing returns dates as "/Date(1399014000000-0700)/": milliseconds since 1970 plus the server's offset. */
    public static function date(string $value): ?string
    {
        if (! preg_match('#Date\((-?\d+)([+-]\d{4})?\)#', $value, $m)) {
            return null;
        }
        $seconds = intdiv((int) $m[1], 1000);
        if (! empty($m[2])) {
            // Shift to the offset Bing reported so the calendar day matches Bing's own reports.
            $sign = $m[2][0] === '-' ? -1 : 1;
            $seconds += $sign * ((int) substr($m[2], 1, 2) * 3600 + (int) substr($m[2], 3, 2) * 60);
        }

        return gmdate('Y-m-d', $seconds);
    }

    private function get(IntegrationConnection $c, string $method): array
    {
        $url = self::API.'/'.$method.'?'.http_build_query(['siteUrl' => $c->property]);
        $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(60)->get($url));

        return $r->json('d') ?? [];
    }

    private function authed(IntegrationConnection $c, callable $call): Response
    {
        if (! $c->access_token || ($c->token_expires_at && $c->token_expires_at->isBefore(now()->addMinute()))) {
            $this->refresh($c);
        }
        $r = $this->send(fn () => $call($c->access_token));
        if ($r->status() === 401) {
            $this->refresh($c);
            $r = $this->send(fn () => $call($c->access_token));
        }
        if ($r->status() === 403) {
            throw new IntegrationException('Bing says this account cannot read that site.');
        }
        if (! $r->successful()) {
            throw new IntegrationException('Bing Webmaster Tools returned an error (HTTP '.$r->status().').', $r->status());
        }

        return $r;
    }

    private function refresh(IntegrationConnection $c): void
    {
        if (! $c->refresh_token) {
            throw IntegrationException::reconnect('The Bing connection has expired. Please reconnect.');
        }
        $r = $this->send(fn () => Http::asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.bing_webmaster.client_id'),
            'client_secret' => config('services.bing_webmaster.client_secret'),
            'refresh_token' => $c->refresh_token,
            'grant_type' => 'refresh_token',
        ]));
        if (in_array($r->status(), [400, 401], true)) {
            throw IntegrationException::reconnect('Bing access was removed or has expired. Please reconnect.');
        }
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new IntegrationException('Could not refresh the Bing connection right now.');
        }
        $c->forceFill([
            'access_token' => $r->json('access_token'),
            'refresh_token' => $r->json('refresh_token') ?: $c->refresh_token,
            'token_expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600)),
        ])->save();
    }

    private function send(callable $fn): Response
    {
        try {
            return $fn();
        } catch (IntegrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new IntegrationException('Could not reach Bing: '.$e->getMessage(), 0, $e);
        }
    }
}
