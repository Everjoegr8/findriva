<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessContact extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'business_location_id', 'type', 'value', 'normalized_value', 'is_primary'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
