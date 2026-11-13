<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class LocalGridScan extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'keyword_id', 'business_location_id', 'requested_by', 'keyword', 'center_lat', 'center_lng',
        'size', 'spacing_km', 'depth', 'provider', 'status', 'points', 'points_found', 'average_position', 'error', 'cost', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'array', 'center_lat' => 'float', 'center_lng' => 'float', 'spacing_km' => 'float', 'size' => 'integer',
            'depth' => 'integer', 'points_found' => 'integer', 'average_position' => 'float', 'cost' => 'float', 'completed_at' => 'datetime',
        ];
    }
}
