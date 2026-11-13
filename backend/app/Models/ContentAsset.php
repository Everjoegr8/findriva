<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentAsset extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'business_id', 'created_by', 'reviewed_by', 'type', 'title', 'status',
        'current_version', 'approved_version', 'review_note', 'reviewed_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentVersion::class)->orderByDesc('version');
    }

    public function current(): HasOne
    {
        return $this->hasOne(ContentVersion::class)->ofMany('version', 'max');
    }

    public function version(int $n): ?ContentVersion
    {
        return $this->hasMany(ContentVersion::class)->where('version', $n)->first();
    }
}
