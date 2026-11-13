<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawledPage extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'crawl_run_id', 'url', 'final_url', 'status_code', 'depth', 'redirect_chain', 'content_type',
        'response_ms', 'title', 'meta_description', 'h1', 'canonical', 'robots_meta', 'x_robots_tag',
        'indexable', 'lang', 'open_graph', 'structured_data', 'extras', 'content_hash',
    ];

    protected function casts(): array
    {
        return [
            'redirect_chain' => 'array',
            'h1' => 'array',
            'open_graph' => 'array',
            'structured_data' => 'array',
            'extras' => 'array',
            'indexable' => 'boolean',
        ];
    }

    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }
}
