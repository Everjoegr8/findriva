<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainProperty extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'domain', 'canonical_host', 'verification_status', 'verification_token', 'verified_at', 'verification_method', 'indexnow_key', 'indexnow_verified_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'indexnow_verified_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
