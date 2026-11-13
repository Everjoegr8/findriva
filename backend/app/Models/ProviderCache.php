<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cached third-party market data (not tenant data), shared to avoid paying twice for the same lookup. */
class ProviderCache extends Model
{
    protected $table = 'provider_cache';

    protected $fillable = ['provider', 'request_hash', 'response', 'retrieved_at', 'expires_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'retrieved_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
