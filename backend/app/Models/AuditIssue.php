<?php

namespace App\Models;

use App\Enums\Severity;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuditIssue extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'crawl_run_id', 'crawled_page_id', 'code', 'category', 'severity', 'url', 'title',
        'why_it_matters', 'recommendation', 'verification_method', 'evidence', 'status',
    ];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'severity' => Severity::class];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function crawlRun(): BelongsTo
    {
        return $this->belongsTo(CrawlRun::class);
    }

    public function action(): HasOne
    {
        return $this->hasOne(ActionTask::class);
    }
}
