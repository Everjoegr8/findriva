<?php

namespace App\Models;

use App\Services\Platform\PlatformConfig;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'type', 'plan', 'billing_status', 'settings', 'billing_provider', 'billing_customer_code', 'billing_subscription_code', 'current_period_end', 'cancel_at_period_end'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'current_period_end' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Workspace $w) {
            if (empty($w->slug)) {
                $base = Str::slug($w->name) ?: 'workspace';
                $slug = $base;
                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = $base.'-'.Str::lower(Str::random(5));
                }
                $w->slug = $slug;
            }
        });
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')->withPivot('role')->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    /** Owners and admins can switch every AI feature off for their workspace; the no-AI versions keep working. */
    public function aiEnabled(): bool
    {
        return (bool) (($this->settings ?? [])['ai_enabled'] ?? true);
    }

    /** @return array<string, int|null> */
    public function entitlements(): array
    {
        return app(PlatformConfig::class)->entitlements((string) $this->plan);
    }
}
