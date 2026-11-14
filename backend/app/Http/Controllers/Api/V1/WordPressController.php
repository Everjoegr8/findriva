<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\SiteConnector;
use App\Services\AuditLogger;
use App\Services\Publishing\PluginPackager;
use App\Services\Publishing\WordPressConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WordPressController extends Controller
{
    public function __construct(private readonly WordPressConnector $wp, private readonly AuditLogger $audit) {}

    public function show(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $c = SiteConnector::query()->where('business_id', $business->id)->where('platform', 'wordpress')->first();

        return response()->json(['data' => [
            'connector' => $c ? $this->present($c) : null,
            'items' => collect(WordPressConnector::ITEMS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            // A preview of what would be published right now, from the current approved facts.
            'preview' => $this->wp->build($business, array_keys(WordPressConnector::ITEMS), $c?->site_url),
            // True when the approved facts changed since the last publish, so the website shows an older version.
            'outdated' => $c && $c->published_at && $c->published_items
                && $this->wp->build($business, $c->published_items, $c->site_url)['version'] !== ($c->manifest['version'] ?? null),
            'website' => $business->website_url,
            'plugin' => ['version' => WordPressConnector::PLUGIN_VERSION, 'download_url' => url('/api/v1/connect/wordpress/plugin.zip')],
            'can_manage' => (bool) request()->user()?->canIn($this->workspace(), Permission::ActionApprove),
        ]]);
    }

    public function pairingCode(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        if (! $business->website_url) {
            throw ValidationException::withMessages(['website' => 'Add your website address in Business profile first.']);
        }

        return response()->json(['data' => $this->wp->startPairing($business, $request->user())]);
    }

    public function publish(Request $request, Business $business): JsonResponse
    {
        // Printing on the customer's website is a change on their channel: owner or admin only.
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        $data = $request->validate(['items' => ['present', 'array'], 'items.*' => ['string', Rule::in(array_keys(WordPressConnector::ITEMS))]]);
        $c = SiteConnector::query()->where('business_id', $business->id)->where('platform', 'wordpress')->where('status', 'connected')->first();
        if (! $c) {
            throw ValidationException::withMessages(['connector' => 'Connect your WordPress site first.']);
        }

        return response()->json(['data' => $this->present($this->wp->publish($c, $business, $request->user(), array_values(array_unique($data['items']))))]);
    }

    public function disconnect(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureInWorkspace($business);
        $c = SiteConnector::query()->where('business_id', $business->id)->where('platform', 'wordpress')->first();
        if ($c) {
            $this->audit->log('wordpress.disconnected', $c, null, ['site_url' => $c->site_url]);
            $c->delete();
        }

        return response()->json(null, 204);
    }

    /** Plugin: exchange a pairing code for a site token. */
    public function pair(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'], 'site_url' => ['required', 'url', 'max:255'],
            'plugin_version' => ['nullable', 'string', 'max:20'], 'wp_version' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json(['data' => $this->wp->pair($data['code'], $data['site_url'], $data['plugin_version'] ?? null, $data['wp_version'] ?? null)]);
    }

    /** Plugin: what to print. An empty homepage means print nothing. */
    public function manifest(Request $request): JsonResponse
    {
        $c = $this->wp->forToken($request->bearerToken());
        if (! $c) {
            return response()->json(['message' => 'This site is not connected to Findriva.'], 401);
        }
        $c->forceFill(['last_seen_at' => now(), 'plugin_version' => $request->header('X-Findriva-Plugin') ? substr((string) $request->header('X-Findriva-Plugin'), 0, 20) : $c->plugin_version])->save();

        return response()->json(['data' => $c->manifest ?? ['version' => null, 'homepage' => []]]);
    }

    public function plugin(PluginPackager $packager): BinaryFileResponse
    {
        return response()->download($packager->zip(), PluginPackager::SLUG.'.zip', ['Content-Type' => 'application/zip']);
    }

    private function present(SiteConnector $c): array
    {
        return [
            'id' => $c->id, 'status' => $c->status, 'site_url' => $c->site_url, 'plugin_version' => $c->plugin_version, 'wp_version' => $c->platform_version,
            'last_seen_at' => $c->last_seen_at?->toIso8601String(), 'pairing_expires_at' => $c->pairing_expires_at?->toIso8601String(),
            'published_items' => $c->published_items ?? [], 'published_at' => $c->published_at?->toIso8601String(),
            'published_version' => $c->manifest['version'] ?? null,
        ];
    }
}
