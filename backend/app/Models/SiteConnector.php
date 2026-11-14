<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class SiteConnector extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'platform', 'status', 'pairing_code_hash', 'pairing_expires_at', 'token_hash', 'site_url',
        'plugin_version', 'platform_version', 'last_seen_at', 'manifest', 'published_items', 'published_at', 'published_by', 'created_by',
    ];

    protected $hidden = ['pairing_code_hash', 'token_hash'];

    protected function casts(): array
    {
        return [
            'pairing_expires_at' => 'datetime', 'last_seen_at' => 'datetime', 'published_at' => 'datetime',
            'manifest' => 'array', 'published_items' => 'array',
        ];
    }
}
