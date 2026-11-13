<?php

namespace App\Models;

use App\Enums\ActionStatus;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionTask extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'audit_issue_id', 'assignee_id', 'created_by', 'approved_by', 'title', 'description',
        'category', 'priority', 'impact', 'effort', 'access_needed', 'requires_approval', 'status',
        'evidence', 'verification', 'approved_at', 'completed_at', 'verified_at', 'due_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ActionStatus::class,
            'requires_approval' => 'boolean',
            'evidence' => 'array',
            'verification' => 'array',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(AuditIssue::class, 'audit_issue_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
