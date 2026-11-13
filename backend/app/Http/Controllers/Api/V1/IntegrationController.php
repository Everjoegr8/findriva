<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\SyncBusinessProfile;
use App\Jobs\SyncSearchConsole;
use App\Models\Business;
use App\Models\DomainProperty;
use App\Models\IndexNowSubmission;
use App\Models\IntegrationConnection;
use App\Models\SearchPerformance;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Integrations\BusinessProfileImporter;
use App\Services\Integrations\GoogleBusinessProfile;
use App\Services\Integrations\GoogleSearchConsole;
use App\Services\Integrations\IndexNow;
use App\Services\Integrations\IntegrationException;
use App\Services\Integrations\SearchConsoleImporter;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connected accounts. Connecting or disconnecting is an owner/admin decision. We only ever ask
 * for read-only access, through the platform's own sign-in; never for passwords.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly GoogleSearchConsole $google,
        private readonly SearchConsoleImporter $importer,
        private readonly IndexNow $indexNow,
        private readonly AuditLogger $audit,
        private readonly GoogleBusinessProfile $profile,
        private readonly BusinessProfileImporter $profileImporter,
    ) {}

    public function index(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $gsc = IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'google_search_console')->first();
        $domain = DomainProperty::query()->where('business_id', $business->id)->first();

        return response()->json(['data' => [
            'google_search_console' => [
                'available' => $this->google->isConfigured(),
                'connection' => $gsc ? $this->present($gsc) : null,
            ],
            'google_business_profile' => [
                'available' => $this->profile->isConfigured(),
                'connection' => ($gbp = IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'google_business_profile')->first()) ? $this->present($gbp) : null,
            ],
            'indexnow' => $domain ? [
                'host' => $domain->canonical_host ?: $domain->domain,
                'key' => $domain->indexnow_key,
                'key_location' => $domain->indexnow_key ? IndexNow::keyLocation($domain) : null,
                'verified_at' => $domain->indexnow_verified_at?->toIso8601String(),
                'recent' => IndexNowSubmission::query()->where('business_id', $business->id)->latest('id')->limit(5)
                    ->get(['id', 'url_count', 'status_code', 'outcome', 'message', 'created_at']),
            ] : null,
            'domain' => $domain?->only(['domain', 'verification_status', 'verification_method', 'verified_at']),
            'can_manage' => (bool) request()->user()?->canIn($this->workspace(), Permission::ActionApprove),
        ]]);
    }

    public function connectGoogle(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        if (! $this->google->isConfigured()) {
            throw ValidationException::withMessages(['google' => 'Google Search Console is not set up on this Findriva installation yet.']);
        }
        $state = Str::random(48);
        Cache::put('oauth:google:'.hash('sha256', $state), [
            'user_id' => $request->user()->id, 'workspace_id' => $this->workspace()->id, 'business_id' => $business->id,
            'provider' => 'google_search_console',
        ], now()->addMinutes(10));

        return response()->json(['data' => ['authorization_url' => $this->google->authorizationUrl($state)]]);
    }

    public function connectBusinessProfile(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        if (! $this->profile->isConfigured()) {
            throw ValidationException::withMessages(['google' => 'Google sign-in is not set up on this Findriva installation yet.']);
        }
        $state = Str::random(48);
        Cache::put('oauth:google:'.hash('sha256', $state), [
            'user_id' => $request->user()->id, 'workspace_id' => $this->workspace()->id, 'business_id' => $business->id,
            'provider' => 'google_business_profile',
        ], now()->addMinutes(10));

        return response()->json(['data' => ['authorization_url' => $this->profile->authorizationUrl($state)]]);
    }

    /** Google redirects the browser here. There is no bearer token, so the one-time state is the proof. */
    public function googleCallback(Request $request, CurrentWorkspace $tenant): RedirectResponse
    {
        $app = rtrim(config('findriva.app_url'), '/');
        $ctx = $request->filled('state') ? Cache::pull('oauth:google:'.hash('sha256', (string) $request->query('state'))) : null;
        if (! $ctx) {
            return redirect()->away($app.'/?integration_error=expired');
        }
        $isProfile = ($ctx['provider'] ?? 'google_search_console') === 'google_business_profile';
        $back = $app.'/businesses/'.$ctx['business_id'].($isProfile ? '/google-profile' : '/results');
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->away($back.'?integration_error=declined');
        }

        $workspace = Workspace::query()->findOrFail($ctx['workspace_id']);
        $user = User::query()->findOrFail($ctx['user_id']);
        if (! $user->canIn($workspace, Permission::ActionApprove)) {
            return redirect()->away($back.'?integration_error=forbidden');
        }

        if ($isProfile) {
            return $tenant->runAs($workspace, fn () => $this->finishBusinessProfile($request, $ctx, $user, $back));
        }

        return $tenant->runAs($workspace, function () use ($request, $ctx, $user, $back) {
            $business = Business::query()->findOrFail($ctx['business_id']);
            try {
                $token = $this->google->exchangeCode((string) $request->query('code'));
                $granted = explode(' ', (string) ($token['scope'] ?? ''));
                if (! in_array(config('findriva.search_console.scope'), $granted, true)) {
                    return redirect()->away($back.'?integration_error=scope');
                }
                $c = IntegrationConnection::query()->firstOrNew(['business_id' => $business->id, 'provider' => 'google_search_console']);
                $c->fill([
                    'connected_by' => $user->id,
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? $c->refresh_token,
                    'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                    'scopes' => $granted,
                    'account_email' => $this->google->accountEmail($token['access_token']),
                    'last_error' => null,
                ])->save();
                $sites = $this->google->sites($c);
                $property = $this->importer->matchProperty($sites, $business->website_url);
                $c->update(['available_properties' => $sites, 'property' => $property, 'status' => $property ? 'connected' : 'needs_property']);
            } catch (IntegrationException) {
                return redirect()->away($back.'?integration_error=google');
            }
            $this->audit->log('integration.connected', $c, null, ['provider' => 'google_search_console', 'property' => $property, 'account' => $c->account_email]);
            if ($property) {
                $this->importer->markDomainVerified($c);
                SyncSearchConsole::dispatch($c->workspace_id, $c->id);
            }

            return redirect()->away($back.'?connected=google');
        });
    }

    private function finishBusinessProfile(Request $request, array $ctx, User $user, string $back): RedirectResponse
    {
        $business = Business::query()->findOrFail($ctx['business_id']);
        try {
            $token = $this->profile->exchangeCode((string) $request->query('code'));
            $granted = explode(' ', (string) ($token['scope'] ?? ''));
            if (! in_array($this->profile->scope(), $granted, true)) {
                return redirect()->away($back.'?integration_error=scope');
            }
            $c = IntegrationConnection::query()->firstOrNew(['business_id' => $business->id, 'provider' => 'google_business_profile']);
            $c->fill([
                'connected_by' => $user->id,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $c->refresh_token,
                'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'scopes' => $granted,
                'account_email' => $this->profile->accountEmail($token['access_token']),
                'last_error' => null,
            ])->save();
            $locations = $this->profile->locations($c);
            $location = $this->profileImporter->matchLocation($locations, $business);
            $c->update(['available_properties' => $locations, 'property' => $location, 'status' => $location ? 'connected' : 'needs_property']);
        } catch (IntegrationException) {
            return redirect()->away($back.'?integration_error=google');
        }
        $this->audit->log('integration.connected', $c, null, ['provider' => 'google_business_profile', 'location' => $location, 'account' => $c->account_email]);
        if ($location) {
            SyncBusinessProfile::dispatch($c->workspace_id, $c->id);
        }

        return redirect()->away($back.'?connected=google_business_profile');
    }

    public function selectProperty(Request $request, Business $business, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureBelongs($connection, $business);
        $data = $request->validate(['property' => ['required', 'string', 'max:255']]);
        if ($connection->provider === 'google_business_profile') {
            if (! collect($connection->available_properties ?? [])->contains('name', $data['property'])) {
                throw ValidationException::withMessages(['property' => 'Choose one of the locations this Google account manages.']);
            }
            $connection->update(['property' => $data['property'], 'status' => 'connected', 'data_through' => null, 'profile_snapshot' => null]);
            $connection->reviews()->delete();
            $connection->metrics()->delete();
            SyncBusinessProfile::dispatch($connection->workspace_id, $connection->id);
            $this->audit->log('integration.property_selected', $connection, null, ['location' => $data['property']]);

            return response()->json(['data' => $this->present($connection->fresh())]);
        }
        if (! collect($connection->available_properties ?? [])->contains('site_url', $data['property'])) {
            throw ValidationException::withMessages(['property' => 'Choose one of the properties this Google account can see.']);
        }
        $connection->update(['property' => $data['property'], 'status' => 'connected', 'data_through' => null]);
        SearchPerformance::query()->where('integration_connection_id', $connection->id)->delete();
        $this->importer->markDomainVerified($connection);
        SyncSearchConsole::dispatch($connection->workspace_id, $connection->id);
        $this->audit->log('integration.property_selected', $connection, null, ['property' => $data['property']]);

        return response()->json(['data' => $this->present($connection->fresh())]);
    }

    public function sync(Business $business, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureBelongs($connection, $business);
        if (! $connection->property || $connection->status === 'revoked') {
            throw ValidationException::withMessages(['connection' => 'Reconnect Google first.']);
        }
        $connection->provider === 'google_business_profile'
            ? SyncBusinessProfile::dispatch($connection->workspace_id, $connection->id)
            : SyncSearchConsole::dispatch($connection->workspace_id, $connection->id);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    public function disconnect(Business $business, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureBelongs($connection, $business);
        // Google revokes the whole grant for this Google account, so keep it while another connection still uses it.
        $shared = IntegrationConnection::query()->withoutGlobalScopes()->where('id', '!=', $connection->id)
            ->where('account_email', $connection->account_email)->whereNotNull('account_email')->where('status', '!=', 'revoked')->exists();
        if (! $shared) {
            $this->google->revoke($connection);
        }
        $this->audit->log('integration.disconnected', $connection, null, ['provider' => $connection->provider, 'property' => $connection->property]);
        // Deleting the connection also deletes the data imported through it.
        $connection->delete();

        return response()->json(null, 204);
    }

    public function indexNowKey(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $domain = $this->domain($business);
        $key = $this->indexNow->keyFor($domain);

        return response()->json(['data' => ['key' => $key, 'filename' => $key.'.txt', 'content' => $key, 'key_location' => IndexNow::keyLocation($domain)]]);
    }

    public function indexNowVerify(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $domain = $this->domain($business);
        $ok = $this->indexNow->verify($domain);

        return response()->json(['data' => ['verified' => $ok, 'key_location' => IndexNow::keyLocation($domain)]], $ok ? 200 : 422);
    }

    public function indexNowSubmit(Request $request, Business $business): JsonResponse
    {
        // Asking search engines to crawl the customer's site is done on their behalf: owner/admin only.
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        $data = $request->validate(['urls' => ['nullable', 'array', 'max:1000'], 'urls.*' => ['string', 'max:2048']]);
        $s = $this->indexNow->submitForBusiness($business, $request->user(), $data['urls'] ?? null);

        return response()->json(['data' => $s->only(['id', 'url_count', 'status_code', 'outcome', 'message', 'created_at'])], $s->outcome === 'accepted' ? 200 : 422);
    }

    private function domain(Business $business): DomainProperty
    {
        $this->ensureInWorkspace($business);
        $domain = DomainProperty::query()->where('business_id', $business->id)->first();
        if (! $domain) {
            throw ValidationException::withMessages(['website' => 'Add your website address first.']);
        }

        return $domain;
    }

    private function present(IntegrationConnection $c): array
    {
        return [
            'id' => $c->id, 'provider' => $c->provider, 'status' => $c->status, 'account_email' => $c->account_email, 'property' => $c->property,
            'available_properties' => $c->available_properties ?? [], 'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            'data_through' => $c->data_through?->toDateString(), 'last_error' => $c->last_error,
            // Business Profile can also post review replies, and only after an owner or admin approves each one.
            'read_only' => $c->provider === 'google_search_console',
        ];
    }
}
