<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private note by Findriva staff about a customer. Not tenant-scoped: only the operations console reads it. */
class SupportNote extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'author_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
