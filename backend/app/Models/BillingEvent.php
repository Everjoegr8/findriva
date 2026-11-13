<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingEvent extends Model
{
    protected $fillable = ['provider', 'event_key', 'event', 'workspace_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
