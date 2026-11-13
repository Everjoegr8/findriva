<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessAlias extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'alias', 'type', 'source'];

    protected function casts(): array
    {
        return [];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
