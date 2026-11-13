<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class SearchPerformance extends Model
{
    use BelongsToWorkspace;

    protected $table = 'search_performance';

    protected $fillable = [
        'workspace_id', 'business_id', 'integration_connection_id', 'source', 'date', 'grain', 'query', 'page',
        'row_hash', 'clicks', 'impressions', 'ctr', 'position',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'float', 'position' => 'float'];
    }
}
