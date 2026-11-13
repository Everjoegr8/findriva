<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrawlRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $facts = $this->site_facts ?? [];

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'start_url' => $this->start_url,
            'status' => $this->status,
            'pages_checked' => $this->pages_checked,
            'issues_found' => $this->issues_found,
            'error' => $this->error,
            'settings' => $this->settings,
            'site' => $facts ? [
                'final_url' => $facts['final_url'] ?? null,
                'uses_https' => $facts['uses_https'] ?? null,
                'http_redirects_to_https' => $facts['http_redirects_to_https'] ?? null,
                'robots' => array_diff_key($facts['robots'] ?? [], ['excerpt' => 1]),
                'sitemap' => $facts['sitemap'] ?? null,
                'budget' => $facts['budget'] ?? null,
                'reconciliation' => $facts['reconciliation'] ?? null,
            ] : null,
            'created_at' => $this->created_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
