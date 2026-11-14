<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Security\Mfa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(10)->letters()->numbers()],
            'workspace_name' => ['nullable', 'string', 'max:120'],
            'workspace_type' => ['nullable', 'in:business,agency'],
        ]);

        [$user, $workspace] = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $workspace = Workspace::create([
                'name' => $data['workspace_name'] ?? $data['name']."'s workspace",
                'type' => $data['workspace_type'] ?? 'business',
            ]);
            $workspace->memberships()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Owner]);
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return [$user, $workspace];
        });

        auth()->setUser($user);
        $audit->log('auth.registered', $user, null, ['email' => $user->email], $workspace->id);
        $user->sendEmailVerificationNotification();

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request, AuditLogger $audit, Mfa $mfa): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those details do not match our records.']);
        }

        if ($user->hasMfa()) {
            // Password was right; the session only starts after the authenticator code.
            return response()->json(['mfa_required' => true, 'challenge' => $mfa->challenge($user)]);
        }

        return $this->issue($user, $audit);
    }

    public function mfaChallenge(Request $request, Mfa $mfa, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:20']]);

        return $this->issue($mfa->resolveChallenge($data['challenge'], $data['code']), $audit, mfa: true);
    }

    /** Signed link from the verification email. Redirects to the dashboard either way. */
    public function verifyEmail(Request $request, string $id, string $hash): RedirectResponse
    {
        $app = rtrim(config('findriva.app_url'), '/');
        $user = User::query()->find($id);
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($app.'/?verified=invalid');
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            app(AuditLogger::class)->log('auth.email_verified', $user, null, null, $user->current_workspace_id);
        }

        return redirect()->away($app.'/?verified=1');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'If your email is not confirmed yet, we sent a new link.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        // Same answer whether or not the account exists, so emails cannot be discovered here.
        PasswordBroker::sendResetLink(['email' => strtolower($data['email'])]);

        return response()->json(['message' => 'If that email has a Findriva account, we sent a reset link.']);
    }

    public function resetPassword(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $status = PasswordBroker::reset(
            ['email' => strtolower($data['email']), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) use ($audit) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // Signing out everywhere: a reset usually means someone else may have the old password.
                $user->tokens()->delete();
                $audit->log('auth.password_reset', $user, null, null, $user->current_workspace_id);
            },
        );
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }

        return response()->json(['message' => 'Your password was changed. Please sign in.']);
    }

    private function issue(User $user, AuditLogger $audit, bool $mfa = false): JsonResponse
    {
        if ($user->disabled_at) {
            throw ValidationException::withMessages(['email' => 'This account is suspended. Email support@eratechnologiz.com.ng if you think this is a mistake.']);
        }
        auth()->setUser($user);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        $audit->log('auth.login', $user, null, ['mfa' => $mfa], $user->current_workspace_id);

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_platform_admin' => $user->is_platform_admin,
            'email_verified' => $user->hasVerifiedEmail(),
            'mfa_enabled' => $user->hasMfa(),
            'current_workspace_id' => $user->current_workspace_id,
            'workspaces' => $user->workspaces()->get()->map(fn (Workspace $w) => [
                'id' => $w->id, 'name' => $w->name, 'type' => $w->type, 'plan' => $w->plan, 'role' => $w->pivot->role,
            ])->values(),
        ];
    }
}
