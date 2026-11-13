<?php

namespace App\Services\Assets;

use App\Models\DigitalAsset;
use App\Services\Crawler\HtmlAnalyzer;
use App\Services\Crawler\SafeHttpClient;

/**
 * Basic public scan of a registered profile/storefront: can it be opened, and what
 * name, description and image does it show to people sharing the link?
 * Uses only the public page; never logs in, never uses the customer's password.
 */
class AssetScanner
{
    public function __construct(private readonly SafeHttpClient $http, private readonly HtmlAnalyzer $analyzer) {}

    public function scan(DigitalAsset $asset): DigitalAsset
    {
        $res = $this->http->get($asset->url);
        $data = [
            'source' => 'public_page_fetch',
            'fetched_at' => now()->toIso8601String(),
            'status_code' => $res->status,
            'final_url' => $res->finalUrl,
        ];

        if ($res->status === null || $res->status >= 400) {
            $status = 'unreachable';
            $data['error'] = $res->error ?? "HTTP {$res->status}";
        } elseif ($res->ok() && $res->isHtml()) {
            $a = $this->analyzer->analyze($res->body, $res->finalUrl);
            $og = $a['open_graph'];
            $data += [
                'title' => $og['og:title'] ?? $a['title'],
                'site_name' => $og['og:site_name'] ?? null,
                'description' => $og['og:description'] ?? $a['meta_description'],
                'image' => $og['og:image'] ?? null,
            ];
            // Many social platforms show a login wall to unauthenticated visitors; say so honestly.
            $status = ($data['title'] || $data['description']) ? 'ok' : 'limited';
        } else {
            $status = 'limited';
        }

        $asset->update(['scan_status' => $status, 'scan_data' => $data, 'last_scanned_at' => now()]);

        return $asset;
    }
}
