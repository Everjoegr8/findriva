<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'connected_by', 'provider', 'status', 'account_email', 'scopes',
        'access_token', 'refresh_token', 'token_expires_at', 'property', 'available_properties',
        'last_synced_at', 'data_through', 'last_error', 'profile_snapshot',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'available_properties' => 'array',
            'profile_snapshot' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'data_through' => 'date',
        ];
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProfileReview::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ProfileMetric::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
