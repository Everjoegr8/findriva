<?php

namespace App\Services\Publishing;

use App\Models\Business;
use App\Models\SiteConnector;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Facts\BusinessFacts;
use App\Services\Fixes\FixGenerator;
use App\Services\Fixes\JsonLd;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Findriva Connect plugin for WordPress. The owner installs it and pastes a one-time pairing
 * code; the plugin then pulls the manifest an owner or admin published and prints it on the
 * homepage. Findriva never logs in to WordPress, and nothing reaches the site without approval.
 */
class WordPressConnector
{
    public const PLUGIN_VERSION = '1.0.0';

    /** What an owner can choose to publish. */
    public const ITEMS = [
        'jsonld' => 'Structured business data (JSON-LD) on the homepage',
        'meta' => 'Homepage description and share preview (Open Graph) tags',
    ];

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly BusinessFacts $facts, private readonly AuditLogger $audit) {}

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** A short code the owner types into the plugin. Valid for 30 minutes and only once. */
    public function startPairing(Business $business, User $by): array
    {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $code = 'FDV-'.substr($raw, 0, 4).'-'.substr($raw, 4);
        $c = SiteConnector::query()->firstOrNew(['business_id' => $business->id, 'platform' => 'wordpress']);
        $c->fill([
            'pairing_code_hash' => self::hash(self::normalizeCode($code)), 'pairing_expires_at' => now()->addMinutes(30), 'created_by' => $by->id,
            'status' => $c->status === 'connected' ? 'connected' : 'pending',
        ])->save();
        $this->audit->log('wordpress.pairing_started', $c, null, ['business_id' => $business->id]);

        return ['code' => $code, 'expires_at' => $c->pairing_expires_at->toIso8601String()];
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** Called by the plugin. Returns the site token once; we keep only its hash. */
    public function pair(string $code, string $siteUrl, ?string $pluginVersion, ?string $wpVersion): array
    {
        $c = SiteConnector::query()->withoutGlobalScopes()->where('platform', 'wordpress')
            ->where('pairing_code_hash', self::hash(self::normalizeCode($code)))->first();
        if (! $c || ! $c->pairing_expires_at || $c->pairing_expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => 'That pairing code is wrong or has expired. Create a new one in Findriva.']);
        }
        $business = Business::query()->withoutGlobalScopes()->findOrFail($c->business_id);
        $site = UrlNormalizer::origin($siteUrl);
        $want = $business->website_url ? preg_replace('/^www\./', '', UrlNormalizer::host($business->website_url)) : null;
        if ($want && preg_replace('/^www\./', '', UrlNormalizer::host($site)) !== $want) {
            throw ValidationException::withMessages(['site_url' => 'This code is for '.$want.'. Install the plugin on that website, or update the website address in Findriva first.']);
        }
        $token = Str::random(64);
        $c->forceFill([
            'status' => 'connected', 'token_hash' => self::hash($token), 'pairing_code_hash' => null, 'pairing_expires_at' => null,
            'site_url' => $site, 'plugin_version' => $pluginVersion ? Str::limit($pluginVersion, 20, '') : null,
            'platform_version' => $wpVersion ? Str::limit($wpVersion, 20, '') : null, 'last_seen_at' => now(),
        ])->save();
        $this->audit->log('wordpress.connected', $c, null, ['site_url' => $site], $c->workspace_id);

        return ['token' => $token, 'business' => $business->name];
    }

    public function forToken(?string $token): ?SiteConnector
    {
        if (! $token) {
            return null;
        }

        return SiteConnector::query()->withoutGlobalScopes()->where('platform', 'wordpress')->where('status', 'connected')
            ->where('token_hash', self::hash($token))->first();
    }

    /** Exactly what the plugin will print, built from approved business facts. */
    public function build(Business $business, array $items, ?string $siteUrl): array
    {
        $facts = $this->facts->for($business);
        $home = rtrim($siteUrl ?: (string) $business->website_url, '/').'/';
        $out = ['version' => null, 'homepage' => []];
        if (in_array('jsonld', $items, true)) {
            $out['homepage']['jsonld'] = JsonLd::encode(JsonLd::localBusiness($facts, $home), false);
        }
        if (in_array('meta', $items, true)) {
            $city = $facts['primary_location']['city'] ?? null;
            $description = $facts['description'] ? Str::limit(preg_replace('/\s+/', ' ', $facts['description']), 155, '…') : null;
            $title = FixGenerator::titleFor($facts['name'], $facts['category'], $city);
            $out['homepage']['meta'] = array_filter([
                'description' => $description,
                'og:type' => 'website', 'og:site_name' => $facts['name'], 'og:title' => $title, 'og:description' => $description,
                'og:url' => $home, 'og:image' => $facts['image'] ?: null,
                'twitter:card' => $facts['image'] ? 'summary_large_image' : 'summary',
            ]);
        }
        $out['version'] = substr(hash('sha256', (string) json_encode($out['homepage'])), 0, 16);

        return $out;
    }

    public function publish(SiteConnector $c, Business $business, User $by, array $items): SiteConnector
    {
        $before = ['items' => $c->published_items, 'version' => $c->manifest['version'] ?? null];
        $manifest = $this->build($business, $items, $c->site_url);
        $c->update(['manifest' => $manifest, 'published_items' => array_values($items), 'published_at' => now(), 'published_by' => $by->id]);
        $this->audit->log('wordpress.published', $c, $before, ['items' => $items, 'version' => $manifest['version']]);

        return $c;
    }
}
