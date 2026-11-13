<?php

namespace App\Services;

use App\Enums\AssetPlatform;
use App\Jobs\RunWebsiteAudit;
use App\Jobs\ScanDigitalAsset;
use App\Models\Business;
use App\Models\BusinessContact;
use App\Models\CrawlRun;
use App\Models\DigitalAsset;
use App\Models\DomainProperty;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawler\UrlNormalizer;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Business logic for onboarding and identity records; controllers stay thin. */
class BusinessService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function onboard(Workspace $workspace, User $user, array $data): Business
    {
        $limit = $workspace->entitlements()['businesses'];
        if ($limit !== null && $workspace->businesses()->count() >= $limit) {
            throw ValidationException::withMessages(['plan' => "Your plan includes {$limit} business(es). Upgrade to add more."]);
        }
        $locLimit = $workspace->entitlements()['locations_per_business'];
        if ($locLimit !== null && count($data['locations'] ?? []) > $locLimit) {
            throw ValidationException::withMessages(['locations' => "Your plan includes {$locLimit} location(s) per business."]);
        }

        $business = DB::transaction(function () use ($workspace, $data) {
            $country = strtoupper($data['country'] ?? 'NG');
            $website = isset($data['website_url']) ? UrlNormalizer::normalize($data['website_url']) : null;

            $business = Business::create(array_merge(
                array_intersect_key($data, array_flip(['name', 'legal_name', 'description', 'primary_category', 'secondary_categories', 'default_language'])),
                ['workspace_id' => $workspace->id, 'country' => $country, 'website_url' => $website, 'status' => 'active', 'onboarded_at' => now()],
            ));

            foreach ($data['aliases'] ?? [] as $a) {
                $business->aliases()->create($a + ['workspace_id' => $workspace->id]);
            }
            $locations = $data['locations'] ?? [];
            $hasPrimary = collect($locations)->contains(fn ($l) => ! empty($l['is_primary']));
            foreach (array_values($locations) as $i => $l) {
                $business->locations()->create(array_merge($l, [
                    'workspace_id' => $workspace->id,
                    'country' => strtoupper($l['country'] ?? $country),
                    'is_primary' => $hasPrimary ? (bool) ($l['is_primary'] ?? false) : $i === 0,
                ]));
            }
            foreach ($data['contacts'] ?? [] as $c) {
                $this->addContact($business, $c);
            }
            foreach ($data['services'] ?? [] as $s) {
                $business->services()->create($s + ['workspace_id' => $workspace->id]);
            }
            foreach ($data['products'] ?? [] as $p) {
                $business->products()->create($p + ['workspace_id' => $workspace->id]);
            }
            if ($website) {
                $this->syncDomain($business);
            }
            foreach ($data['assets'] ?? [] as $a) {
                $this->addAsset($business, $a, dispatchScan: false);
            }

            return $business;
        });

        $this->audit->log('business.onboarded', $business, null, [
            'name' => $business->name, 'has_website' => $business->hasWebsite(),
            'locations' => count($data['locations'] ?? []), 'assets' => count($data['assets'] ?? []),
        ]);

        foreach ($business->assets as $asset) {
            ScanDigitalAsset::dispatch($workspace->id, $asset->id)->afterCommit();
        }
        if ($business->hasWebsite() && ($data['start_audit'] ?? true)) {
            $this->startAudit($business, $user);
        }

        return $business;
    }

    public function addContact(Business $business, array $c): BusinessContact
    {
        $normalized = match ($c['type']) {
            'phone', 'whatsapp' => Phone::normalize($c['value'], $business->country),
            'email' => strtolower(trim($c['value'])),
            'url' => UrlNormalizer::normalize($c['value']),
            default => null,
        };

        return $business->contacts()->create($c + ['workspace_id' => $business->workspace_id, 'normalized_value' => $normalized]);
    }

    public function addAsset(Business $business, array $a, bool $dispatchScan = true): DigitalAsset
    {
        $url = UrlNormalizer::normalize($a['url']) ?? $a['url'];
        $platform = isset($a['platform']) ? AssetPlatform::from($a['platform']) : AssetPlatform::detect($url);
        if ($business->assets()->where('url', $url)->exists()) {
            throw ValidationException::withMessages(['url' => 'This link is already registered for the business.']);
        }

        $asset = $business->assets()->create([
            'workspace_id' => $business->workspace_id,
            'platform' => $platform,
            'url' => $url,
            'handle' => $a['handle'] ?? self::guessHandle($url, $platform),
            'ownership_status' => $a['ownership_status'] ?? 'claimed',
            'connection_state' => 'not_connected',
            'scan_status' => 'pending',
        ]);

        if ($dispatchScan) {
            ScanDigitalAsset::dispatch($business->workspace_id, $asset->id)->afterCommit();
        }

        return $asset;
    }

    public function syncDomain(Business $business): ?DomainProperty
    {
        if (! $business->hasWebsite()) {
            return null;
        }
        $host = UrlNormalizer::host($business->website_url);

        return DomainProperty::query()->firstOrCreate(
            ['business_id' => $business->id, 'domain' => $host],
            ['workspace_id' => $business->workspace_id, 'canonical_host' => parse_url($business->website_url, PHP_URL_HOST),
                'verification_status' => 'unverified', 'verification_token' => 'findriva-verify='.Str::random(32)],
        );
    }

    public function startAudit(Business $business, ?User $user, ?string $url = null): CrawlRun
    {
        $workspace = $business->workspace;
        $url = $url ?? $business->website_url;
        if (! $url) {
            throw ValidationException::withMessages(['website_url' => 'Add a website first. Businesses without a website get identity and profile checks instead.']);
        }

        $monthly = $workspace->entitlements()['crawls_per_month'];
        $used = UsageEvent::query()->where('workspace_id', $workspace->id)->where('action', 'website_crawl')
            ->where('created_at', '>=', now()->startOfMonth())->count();
        $queued = CrawlRun::query()->where('business_id', $business->id)->whereIn('status', ['queued', 'running'])->exists();
        if ($queued) {
            throw ValidationException::withMessages(['audit' => 'An audit is already running for this business.']);
        }
        if ($monthly !== null && $used >= $monthly) {
            throw ValidationException::withMessages(['plan' => "You have used all {$monthly} website audits included this month."]);
        }

        $domain = $this->syncDomain($business);
        $run = CrawlRun::create([
            'workspace_id' => $business->workspace_id,
            'business_id' => $business->id,
            'domain_property_id' => $domain?->id,
            'requested_by' => $user?->id,
            'start_url' => $url,
            'status' => 'queued',
            'settings' => ['max_pages' => $workspace->entitlements()['pages_per_crawl'], 'max_depth' => config('findriva.crawler.max_depth')],
        ]);
        $this->audit->log('audit.requested', $run, null, ['start_url' => $url]);
        RunWebsiteAudit::dispatch($business->workspace_id, $run->id)->afterCommit();

        return $run;
    }

    private static function guessHandle(string $url, AssetPlatform $platform): ?string
    {
        if (! $platform->isSocial()) {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $first = explode('/', $path)[0] ?? '';

        return $first !== '' ? ltrim($first, '@') : null;
    }
}
