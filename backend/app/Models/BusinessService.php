<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessService extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'name', 'description', 'service_areas', 'url'];

    protected function casts(): array
    {
        return ['service_areas' => 'array'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
