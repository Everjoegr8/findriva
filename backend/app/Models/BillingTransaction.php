<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingTransaction extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'provider', 'reference', 'plan', 'amount', 'currency', 'status', 'provider_data', 'paid_at'];

    protected function casts(): array
    {
        return ['provider_data' => 'array', 'paid_at' => 'datetime', 'amount' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
