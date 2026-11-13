<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Those details do not match our records.']);
        }

        auth()->setUser($user);
        $audit->log('auth.login', $user, null, null, $user->current_workspace_id);

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
            'current_workspace_id' => $user->current_workspace_id,
            'workspaces' => $user->workspaces()->get()->map(fn (Workspace $w) => [
                'id' => $w->id, 'name' => $w->name, 'type' => $w->type, 'plan' => $w->plan, 'role' => $w->pivot->role,
            ])->values(),
        ];
    }
}
