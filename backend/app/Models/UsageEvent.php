<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class UsageEvent extends Model
{
    use BelongsToWorkspace;

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'provider', 'action', 'units', 'estimated_cost', 'context'];

    protected function casts(): array
    {
        return ['context' => 'array', 'estimated_cost' => 'decimal:6', 'created_at' => 'datetime'];
    }
}
