<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentVersion extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'content_asset_id', 'created_by', 'version', 'body', 'generator', 'source_facts', 'missing_facts', 'claim_flags',
    ];

    protected function casts(): array
    {
        return ['source_facts' => 'array', 'missing_facts' => 'array', 'claim_flags' => 'array', 'version' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class, 'content_asset_id');
    }
}
