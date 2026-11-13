<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActionTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'priority' => $this->priority,
            'impact' => $this->impact,
            'effort' => $this->effort,
            'access_needed' => $this->access_needed,
            'requires_approval' => $this->requires_approval,
            'status' => $this->status->value,
            'allowed_next' => array_map(fn ($s) => $s->value, $this->status->allowedNext()),
            'evidence' => $this->evidence,
            'verification' => $this->verification,
            'issue' => $this->whenLoaded('issue', fn () => $this->issue ? [
                'id' => $this->issue->id,
                'code' => $this->issue->code,
                'severity' => $this->issue->severity->value,
                'url' => $this->issue->url,
                'why_it_matters' => $this->issue->why_it_matters,
                'verification_method' => $this->issue->verification_method,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only('id', 'name')),
            'approved_at' => $this->approved_at,
            'completed_at' => $this->completed_at,
            'verified_at' => $this->verified_at,
            'due_at' => $this->due_at,
            'created_at' => $this->created_at,
        ];
    }
}
