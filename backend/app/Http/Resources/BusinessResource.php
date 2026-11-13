<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'description' => $this->description,
            'primary_category' => $this->primary_category,
            'secondary_categories' => $this->secondary_categories ?? [],
            'country' => $this->country,
            'default_language' => $this->default_language,
            'website_url' => $this->website_url,
            'has_website' => $this->hasWebsite(),
            'status' => $this->status,
            'onboarded_at' => $this->onboarded_at,
            'locations_count' => $this->whenCounted('locations'),
            'assets_count' => $this->whenCounted('assets'),
            'open_issues_count' => $this->whenCounted('open_issues_count', fn () => $this->open_issues_count),
            'aliases' => $this->whenLoaded('aliases'),
            'locations' => $this->whenLoaded('locations'),
            'contacts' => $this->whenLoaded('contacts'),
            'services' => $this->whenLoaded('services'),
            'products' => $this->whenLoaded('products'),
            'assets' => $this->whenLoaded('assets'),
            'domains' => $this->whenLoaded('domains', fn () => $this->domains->map->only('id', 'domain', 'canonical_host', 'verification_status', 'verified_at')),
            'latest_audit' => $this->whenLoaded('latestCrawlRun', fn () => $this->latestCrawlRun ? new CrawlRunResource($this->latestCrawlRun) : null),
            'created_at' => $this->created_at,
        ];
    }
}
