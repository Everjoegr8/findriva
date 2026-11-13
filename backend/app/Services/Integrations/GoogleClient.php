<?php

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google OAuth 2.0 plumbing shared by Google products. We never see the customer's Google
 * password; Google issues a token we can revoke, and the customer can revoke it too.
 */
abstract class GoogleClient
{
    /** Shown in error messages, e.g. "Google Search Console". */
    protected string $product = 'Google';

    protected const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    protected const USERINFO = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /** The OAuth scope this product needs. */
    abstract public function scope(): string;

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => $this->scope().' openid email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string} */
    public function exchangeCode(string $code): array
    {
        $r = $this->send(fn () => Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]));
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new IntegrationException('Google did not accept the sign-in. Please try connecting again.');
        }

        return $r->json();
    }

    public function accountEmail(string $accessToken): ?string
    {
        $r = $this->send(fn () => Http::withToken($accessToken)->timeout(15)->get(self::USERINFO));

        return $r->successful() ? $r->json('email') : null;
    }

    public function revoke(IntegrationConnection $c): void
    {
        $token = $c->refresh_token ?: $c->access_token;
        if ($token) {
            try {
                Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
            } catch (Throwable) {
                // Revoking is best effort; we delete our copy of the token either way.
            }
        }
    }

    /** Calls the API with a fresh access token, refreshing it once if Google says it expired. */
    protected function authed(IntegrationConnection $c, callable $call): Response
    {
        if (! $c->access_token || ($c->token_expires_at && $c->token_expires_at->isBefore(now()->addMinute()))) {
            $this->refresh($c);
        }
        $r = $this->send(fn () => $call($c->access_token));
        if ($r->status() === 401) {
            $this->refresh($c);
            $r = $this->send(fn () => $call($c->access_token));
        }
        if ($r->status() === 403) {
            throw new IntegrationException($this->forbiddenMessage());
        }
        if (! $r->successful()) {
            throw new IntegrationException($this->product.' returned an error (HTTP '.$r->status().').', $r->status());
        }

        return $r;
    }

    protected function forbiddenMessage(): string
    {
        return 'Google says this account does not have access.';
    }

    protected function refresh(IntegrationConnection $c): void
    {
        if (! $c->refresh_token) {
            throw IntegrationException::reconnect('The Google connection has expired. Please reconnect.');
        }
        $r = $this->send(fn () => Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $c->refresh_token,
            'grant_type' => 'refresh_token',
        ]));
        if ($r->status() === 400 || $r->status() === 401) {
            throw IntegrationException::reconnect('Google access was removed or has expired. Please reconnect.');
        }
        if (! $r->successful() || ! $r->json('access_token')) {
            throw new IntegrationException('Could not refresh the Google connection right now.');
        }
        $c->forceFill([
            'access_token' => $r->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600)),
        ])->save();
    }

    protected function send(callable $fn): Response
    {
        try {
            return $fn();
        } catch (IntegrationException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new IntegrationException('Could not reach Google: '.$e->getMessage(), 0, $e);
        }
    }
}
