<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Keyword extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'keyword_cluster_id', 'keyword', 'normalized', 'intent', 'origin', 'origin_detail',
        'market', 'language', 'search_volume', 'competition', 'cpc', 'cpc_currency', 'metrics_provider', 'metrics_retrieved_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'origin_detail' => 'array',
            'search_volume' => 'integer',
            'competition' => 'float',
            'cpc' => 'float',
            'metrics_retrieved_at' => 'datetime',
        ];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'keyword_cluster_id');
    }

    public static function normalize(string $phrase): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($phrase))));
    }
}
