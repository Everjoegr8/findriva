<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use App\Services\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_matches_rfc_6238_reference_values(): void
    {
        // RFC 6238 appendix B, SHA-1 secret "12345678901234567890".
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
        $this->assertNotNull(Totp::verify($secret, '287082', 59 + 30), 'one step of drift is allowed');
        $this->assertNull(Totp::verify($secret, '287082', 59 + 90));
    }

    public function test_registration_sends_a_verification_link_that_verifies_and_gates_owner_actions(): void
    {
        Notification::fake();
        config(['findriva.app_url' => 'http://app.test']);
        $res = $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'Ada@Example.com', 'password' => 'a-strong-pass-1'])->assertCreated();
        $res->assertJsonPath('user.email_verified', false);
        $user = User::where('email', 'ada@example.com')->first();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function ($n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });

        // Unverified owners cannot publish, connect accounts, invite people or pay.
        $ws = $res->json('user.workspaces.0.id');
        $h = ['Authorization' => 'Bearer '.$res->json('token'), 'X-Workspace-Id' => (string) $ws];
        $this->postJson('/api/v1/workspace/billing/checkout', ['plan' => 'starter'], $h)->assertForbidden();

        $this->get(str_replace('/'.$user->id.'/', '/999/', $url))->assertForbidden(); // tampered signature
        $this->get($url)->assertRedirect('http://app.test/?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_password_reset_never_reveals_accounts_and_signs_out_everywhere(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.com', 'password' => 'old-password-1']);
        $user->createToken('old');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk()
            ->assertJsonPath('message', 'If that email has a Findriva account, we sent a reset link.');
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'owner@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->postJson('/api/v1/auth/password/reset', ['token' => 'wrong', 'email' => 'owner@example.com', 'password' => 'new-password-22', 'password_confirmation' => 'new-password-22'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'email' => 'owner@example.com', 'password' => 'new-password-22', 'password_confirmation' => 'new-password-22'])
            ->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'new-password-22'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_two_step_sign_in_setup_challenge_replay_and_recovery_codes(): void
    {
        [$user] = $this->member();
        $user->update(['password' => 'pass-word-1234']);
        $setup = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/mfa/setup')->assertOk()->json('data');
        $this->assertStringStartsWith('otpauth://totp/Findriva%3A', $setup['otpauth_uri']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/mfa/confirm', ['code' => '000000'])->assertUnprocessable();
        $codes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/mfa/confirm', ['code' => Totp::code($setup['secret'], Totp::step())])
            ->assertOk()->json('data.recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertStringNotContainsString($setup['secret'], $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->getContent());
        $this->assertNotContains($codes[0], $user->fresh()->mfa_recovery_codes, 'Recovery codes are stored hashed.');

        // The password alone no longer signs in.
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'pass-word-1234'])->assertOk();
        $login->assertJsonPath('mfa_required', true)->assertJsonMissingPath('token');

        // The code used to enable it cannot be replayed.
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $login->json('challenge'), 'code' => Totp::code($setup['secret'], Totp::step())])->assertUnprocessable();

        $this->travel(31)->seconds();
        $ok = $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $login->json('challenge'), 'code' => Totp::code($setup['secret'], Totp::step())])->assertOk();
        $this->assertNotEmpty($ok->json('token'));
        // The challenge is single use.
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $login->json('challenge'), 'code' => Totp::code($setup['secret'], Totp::step())])->assertUnprocessable();

        // A recovery code works once.
        $again = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'pass-word-1234'])->json('challenge');
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $again, 'code' => $codes[0]])->assertOk();
        $third = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'pass-word-1234'])->json('challenge');
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $third, 'code' => $codes[0]])->assertUnprocessable();

        // Turning it off needs the password and a code. (Wait out the sign-in rate limit first.)
        $this->travel(61)->seconds();
        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/account/mfa/disable', ['password' => 'wrong', 'code' => $codes[1]])->assertUnprocessable();
        $this->actingAs($user->fresh(), 'sanctum')->postJson('/api/v1/account/mfa/disable', ['password' => 'pass-word-1234', 'code' => $codes[1]])->assertOk();
        $this->assertFalse($user->fresh()->hasMfa());
    }

    public function test_challenge_locks_after_five_wrong_codes(): void
    {
        Queue::fake();
        [$user] = $this->member();
        $user->update(['password' => 'pass-word-1234']);
        $secret = $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/mfa/setup')->json('data.secret');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/account/mfa/confirm', ['code' => Totp::code($secret, Totp::step())]);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'pass-word-1234'])->json('challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => '111111']);
        }
        $this->travel(31)->seconds();
        $this->postJson('/api/v1/auth/mfa/challenge', ['challenge' => $challenge, 'code' => Totp::code($secret, Totp::step())])
            ->assertUnprocessable()->assertJsonValidationErrors('challenge');
    }
}
