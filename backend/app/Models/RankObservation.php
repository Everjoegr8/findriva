<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankObservation extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'keyword_id', 'provider', 'search_engine', 'market', 'language', 'device', 'depth',
        'position', 'absolute_position', 'url', 'serp_features', 'top_results', 'cost', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'serp_features' => 'array', 'top_results' => 'array', 'checked_at' => 'datetime',
            'position' => 'integer', 'absolute_position' => 'integer', 'depth' => 'integer', 'cost' => 'float',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
