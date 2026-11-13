<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'current_workspace_id'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_pending_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_pending_secret' => 'encrypted',
            'mfa_recovery_codes' => 'array',
        ];
    }

    public function hasMfa(): bool
    {
        return $this->mfa_enabled_at !== null && $this->mfa_secret !== null;
    }

    /** Verification and reset emails link to the dashboard, which calls the API. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPassword($token));
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function roleIn(Workspace|int $workspace): ?WorkspaceRole
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;
        $role = WorkspaceMembership::query()
            ->where('workspace_id', $id)
            ->where('user_id', $this->id)
            ->value('role');

        if ($role instanceof WorkspaceRole || $role === null) {
            return $role;
        }

        return WorkspaceRole::from($role);
    }

    public function canIn(Workspace|int $workspace, Permission $permission): bool
    {
        return (bool) $this->roleIn($workspace)?->allows($permission);
    }
}
