<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

class ProfileMetric extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'business_id', 'integration_connection_id', 'date', 'metric', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
