<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class Competitor extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'domain', 'name', 'kind', 'source', 'status',
        'shared_keywords', 'best_position', 'average_position', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'shared_keywords' => 'integer', 'best_position' => 'integer', 'average_position' => 'float'];
    }
}
