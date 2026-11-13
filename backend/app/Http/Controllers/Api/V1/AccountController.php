<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Security\Mfa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own security settings. */
class AccountController extends Controller
{
    public function __construct(private readonly Mfa $mfa) {}

    public function security(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(['data' => [
            'email' => $u->email, 'email_verified' => $u->hasVerifiedEmail(),
            'mfa_enabled' => $u->hasMfa(), 'mfa_enabled_at' => $u->mfa_enabled_at?->toIso8601String(),
            'recovery_codes_left' => $u->hasMfa() ? count($u->mfa_recovery_codes ?? []) : null,
        ]]);
    }

    public function beginMfa(Request $request): JsonResponse
    {
        if ($request->user()->hasMfa()) {
            abort(422, 'Two-step sign-in is already on.');
        }

        return response()->json(['data' => $this->mfa->begin($request->user())]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        return response()->json(['data' => ['recovery_codes' => $this->mfa->confirm($request->user(), $data['code'])]]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']]);
        $this->mfa->disable($request->user(), $data['password'], $data['code']);

        return response()->json(['data' => ['mfa_enabled' => false]]);
    }
}
