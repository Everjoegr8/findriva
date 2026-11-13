<?php

namespace App\Http\Resources;

use App\Services\Audit\IssueCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Shaped around the questions an owner asks: why it matters, what we found, what to do, how we will check. */
class AuditIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $def = IssueCatalog::all()[$this->code] ?? null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'category' => $this->category,
            'severity' => $this->severity->value,
            'status' => $this->status,
            'title' => $this->title,
            'technical_name' => $def['technical'] ?? null,
            'url' => $this->url,
            'why_it_matters' => $this->why_it_matters,
            'what_we_found' => $this->evidence,
            'recommendation' => $this->recommendation,
            'access_needed' => $def['access'] ?? null,
            'verification_method' => $this->verification_method,
            'crawl_run_id' => $this->crawl_run_id,
            'action' => $this->whenLoaded('action', fn () => $this->action?->only('id', 'status')),
            'detected_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
