<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessLocation extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'label', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country', 'latitude', 'longitude', 'location_type', 'address_is_public', 'service_radius_km', 'service_areas', 'opening_hours', 'is_primary'];

    protected function casts(): array
    {
        return ['service_areas' => 'array', 'opening_hours' => 'array', 'address_is_public' => 'boolean', 'is_primary' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
