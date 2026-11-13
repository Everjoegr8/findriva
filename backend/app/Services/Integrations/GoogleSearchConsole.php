<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\Http;

/** Google Search Console with the read-only scope. */
class GoogleSearchConsole extends GoogleClient
{
    private const API = 'https://www.googleapis.com/webmasters/v3';

    protected string $product = 'Google Search Console';

    public function scope(): string
    {
        return (string) config('findriva.search_console.scope');
    }

    /** @return list<array{site_url: string, permission: string}> */
    public function sites(IntegrationConnection $c): array
    {
        $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(20)->get(self::API.'/sites'));

        return collect($r->json('siteEntry') ?? [])
            ->map(fn ($s) => ['site_url' => (string) $s['siteUrl'], 'permission' => (string) ($s['permissionLevel'] ?? 'unknown')])
            ->values()->all();
    }

    /** One page of Search Analytics rows. */
    public function query(IntegrationConnection $c, array $body): array
    {
        $url = self::API.'/sites/'.rawurlencode($c->property).'/searchAnalytics/query';
        $r = $this->authed($c, fn (string $t) => Http::withToken($t)->acceptJson()->timeout(60)->post($url, $body));

        return $r->json('rows') ?? [];
    }

    protected function forbiddenMessage(): string
    {
        return 'Google says this account cannot read that Search Console property.';
    }
}
