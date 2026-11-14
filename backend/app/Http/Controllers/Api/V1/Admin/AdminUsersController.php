<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupportNote;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/** Customer accounts, for support. Staff never see passwords, MFA secrets or tokens. */
class AdminUsersController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());
        $page = User::query()
            ->withCount('workspaces')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', '%'.strtolower($search).'%')))
            ->when($request->string('filter')->toString(), fn ($q, $f) => match ($f) {
                'unverified' => $q->whereNull('email_verified_at'),
                'disabled' => $q->whereNotNull('disabled_at'),
                'staff' => $q->where('is_platform_admin', true),
                'no_mfa' => $q->whereNull('mfa_enabled_at'),
                default => $q,
            })
            ->latest('id')->paginate(50);
        $page->getCollection()->transform(fn (User $u) => $this->row($u));

        return response()->json($page);
    }

    public function show(User $user): JsonResponse
    {
        $workspaces = DB::table('workspace_memberships as m')->join('workspaces as w', 'w.id', '=', 'm.workspace_id')
            ->where('m.user_id', $user->id)->whereNull('w.deleted_at')
            ->get(['w.id', 'w.name', 'w.plan', 'w.billing_status', 'm.role']);

        return response()->json(['data' => [
            ...$this->row($user),
            'workspaces' => $workspaces,
            'activity' => AuditLog::query()->where('actor_id', $user->id)->latest('id')->limit(30)
                ->get(['id', 'action', 'target_type', 'target_id', 'workspace_id', 'ip_address', 'created_at']),
            'notes' => SupportNote::query()->with('author:id,name')->where('user_id', $user->id)->latest('id')->get(),
        ]]);
    }

    public function resendVerification(User $user): JsonResponse
    {
        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['user' => 'This email address is already confirmed.']);
        }
        $user->sendEmailVerificationNotification();
        $this->audit->log('admin.verification_resent', $user, null, null, $user->current_workspace_id);

        return response()->json(['message' => 'A new confirmation link was sent to '.$user->email.'.']);
    }

    /** Sends the normal reset email to the customer. Staff never set or see a password. */
    public function sendPasswordReset(User $user): JsonResponse
    {
        Password::broker()->sendResetLink(['email' => $user->email]);
        $this->audit->log('admin.password_reset_sent', $user, null, null, $user->current_workspace_id);

        return response()->json(['message' => 'A password reset link was sent to '.$user->email.'.']);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if ($user->is($request->user()) || $user->is_platform_admin) {
            throw ValidationException::withMessages(['user' => 'Staff accounts cannot be suspended here. Remove operations access on the server first.']);
        }
        $user->forceFill(['disabled_at' => now(), 'disabled_reason' => $data['reason']])->save();
        $user->tokens()->delete(); // signs them out everywhere
        $this->audit->log('admin.user_disabled', $user, null, ['reason' => $data['reason']], $user->current_workspace_id);

        return response()->json(['data' => $this->row($user)]);
    }

    public function enable(User $user): JsonResponse
    {
        $user->forceFill(['disabled_at' => null, 'disabled_reason' => null])->save();
        $this->audit->log('admin.user_enabled', $user, null, null, $user->current_workspace_id);

        return response()->json(['data' => $this->row($user)]);
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'email_verified' => (bool) $u->email_verified_at, 'mfa_enabled' => (bool) $u->mfa_enabled_at,
            'is_platform_admin' => (bool) $u->is_platform_admin, 'workspaces_count' => $u->workspaces_count ?? null,
            'last_login_at' => $u->last_login_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String(),
            'disabled_at' => $u->disabled_at?->toIso8601String(), 'disabled_reason' => $u->disabled_reason,
        ];
    }
}
