<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileReview extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'integration_connection_id', 'external_id', 'reviewer_name', 'rating', 'comment',
        'reviewed_at', 'source_updated_at', 'reply_comment', 'reply_updated_at', 'reply_status', 'reply_draft',
        'drafted_by', 'approved_by', 'approved_at', 'posted_at', 'reply_error',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer', 'reviewed_at' => 'datetime', 'source_updated_at' => 'datetime', 'reply_updated_at' => 'datetime',
            'approved_at' => 'datetime', 'posted_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
