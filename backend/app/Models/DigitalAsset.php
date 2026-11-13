<?php

namespace App\Models;

use App\Enums\AssetPlatform;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalAsset extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'platform', 'url', 'handle', 'ownership_status', 'connection_state', 'scan_status', 'scan_data', 'last_scanned_at'];

    protected function casts(): array
    {
        return ['platform' => AssetPlatform::class, 'scan_data' => 'array', 'last_scanned_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
