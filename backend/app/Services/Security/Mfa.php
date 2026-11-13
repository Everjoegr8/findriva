<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Two-step sign-in with an authenticator app, plus single-use recovery codes. */
class Mfa
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function begin(User $user): array
    {
        $secret = Totp::generateSecret();
        $user->forceFill(['mfa_pending_secret' => $secret])->save();

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, $user->email)];
    }

    /** @return list<string> plain recovery codes, shown once */
    public function confirm(User $user, string $code): array
    {
        $secret = $user->mfa_pending_secret;
        $step = $secret ? Totp::verify($secret, $code) : null;
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code did not match. Check your phone\'s time is set automatically and try the newest code.']);
        }
        $codes = collect(range(1, 10))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'mfa_secret' => $secret, 'mfa_pending_secret' => null, 'mfa_enabled_at' => now(), 'mfa_last_used_step' => $step,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
        ])->save();
        $this->audit->log('auth.mfa_enabled', $user, null, null, $user->current_workspace_id);

        return $codes;
    }

    public function disable(User $user, string $password, string $code): void
    {
        if (! Hash::check($password, $user->password) || ! $this->check($user, $code)) {
            throw ValidationException::withMessages(['code' => 'Your password or code was not right.']);
        }
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_used_step' => null])->save();
        $this->audit->log('auth.mfa_disabled', $user, null, null, $user->current_workspace_id);
    }

    /** Accepts a current authenticator code (each used once) or an unused recovery code. */
    public function check(User $user, string $code): bool
    {
        if (! $user->hasMfa()) {
            return false;
        }
        $step = Totp::verify($user->mfa_secret, $code);
        if ($step !== null) {
            if ($user->mfa_last_used_step !== null && $step <= $user->mfa_last_used_step) {
                return false; // replayed code
            }
            $user->forceFill(['mfa_last_used_step' => $step])->save();

            return true;
        }
        $codes = $user->mfa_recovery_codes ?? [];
        foreach ($codes as $i => $hash) {
            if (Hash::check(strtolower(trim($code)), $hash)) {
                unset($codes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                $this->audit->log('auth.mfa_recovery_code_used', $user, null, ['remaining' => count($codes)], $user->current_workspace_id);

                return true;
            }
        }

        return false;
    }

    /** After a correct password: a short-lived challenge to finish sign-in with a code. */
    public function challenge(User $user): string
    {
        $token = Str::random(48);
        Cache::put('mfa:challenge:'.hash('sha256', $token), ['user_id' => $user->id, 'attempts' => 0], now()->addMinutes(5));

        return $token;
    }

    public function resolveChallenge(string $token, string $code): User
    {
        $key = 'mfa:challenge:'.hash('sha256', $token);
        $data = Cache::get($key);
        if (! $data) {
            throw ValidationException::withMessages(['challenge' => 'This sign-in has expired. Please sign in again.']);
        }
        $user = User::query()->findOrFail($data['user_id']);
        if (! $this->check($user, $code)) {
            $data['attempts']++;
            $data['attempts'] >= 5 ? Cache::forget($key) : Cache::put($key, $data, now()->addMinutes(5));
            throw ValidationException::withMessages(['code' => 'That code did not match.']);
        }
        Cache::forget($key);

        return $user;
    }
}
