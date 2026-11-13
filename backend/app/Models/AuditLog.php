<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of who changed what. Not tenant-scoped globally so platform admins can review it. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'actor_id', 'action', 'target_type', 'target_id', 'before', 'after', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
