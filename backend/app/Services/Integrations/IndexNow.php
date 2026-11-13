<?php

namespace App\Services\Integrations;

use App\Models\Business;
use App\Models\CrawledPage;
use App\Models\CrawlRun;
use App\Models\DomainProperty;
use App\Models\IndexNowSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Crawler\SafeHttpClient;
use App\Services\Crawler\UrlNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IndexNow tells Bing, Yandex, Seznam, Naver and others that pages changed. It needs a key file
 * on the customer's own site, which proves they control it. Submitting is a request to crawl,
 * not a promise that pages will be indexed.
 */
class IndexNow
{
    public function __construct(private readonly SafeHttpClient $http, private readonly AuditLogger $audit) {}

    public function keyFor(DomainProperty $domain): string
    {
        if (! $domain->indexnow_key) {
            $domain->update(['indexnow_key' => Str::lower(Str::random(32))]);
        }

        return $domain->indexnow_key;
    }

    public static function keyLocation(DomainProperty $domain): string
    {
        return 'https://'.($domain->canonical_host ?: $domain->domain).'/'.$domain->indexnow_key.'.txt';
    }

    /** Re-checks the key file on the customer's site (through the SSRF-safe client). */
    public function verify(DomainProperty $domain): bool
    {
        $this->keyFor($domain);
        $result = $this->http->get(self::keyLocation($domain));
        $ok = $result->ok() && trim($result->body) === $domain->indexnow_key;
        $domain->update(['indexnow_verified_at' => $ok ? now() : null]);
        $this->audit->log('indexnow.key_checked', $domain, null, ['ok' => $ok, 'status' => $result->status]);

        return $ok;
    }

    /** Submits the business's indexable pages from the latest audit (or the given URLs on the same host). */
    public function submitForBusiness(Business $business, User $by, ?array $urls = null): IndexNowSubmission
    {
        $domain = DomainProperty::query()->where('business_id', $business->id)->first();
        if (! $domain || ! $domain->indexnow_verified_at) {
            throw ValidationException::withMessages(['indexnow' => 'Add the IndexNow key file to your site and check it first.']);
        }
        $host = $domain->canonical_host ?: $domain->domain;
        $urls = $urls ?? $this->indexableUrls($business, $host);
        $urls = collect($urls)->map(fn ($u) => UrlNormalizer::normalize($u))->filter()
            ->filter(fn ($u) => strtolower((string) parse_url($u, PHP_URL_HOST)) === strtolower($host))->unique()->take((int) config('findriva.indexnow.max_urls'))->values()->all();
        if (! $urls) {
            throw ValidationException::withMessages(['urls' => 'There are no pages on '.$host.' to submit. Run an audit first.']);
        }

        return $this->send($business->workspace_id, $business->id, $by->id, $host, $domain->indexnow_key, self::keyLocation($domain), $urls);
    }

    /** Findriva's own public pages, submitted with the platform key. */
    public function submitPlatformUrls(int $workspaceId, ?int $businessId, array $urls): ?IndexNowSubmission
    {
        $key = config('findriva.indexnow.platform_key');
        if (! $key) {
            return null;
        }
        $base = rtrim(config('findriva.public_url'), '/');
        $host = parse_url($base, PHP_URL_HOST);

        return $this->send($workspaceId, $businessId, null, $host, $key, $base.'/'.$key.'.txt', $urls);
    }

    private function send(int $workspaceId, ?int $businessId, ?int $userId, string $host, string $key, string $keyLocation, array $urls): IndexNowSubmission
    {
        $status = null;
        $message = null;
        try {
            $r = Http::acceptJson()->timeout(20)->post(config('findriva.indexnow.endpoint'), [
                'host' => $host, 'key' => $key, 'keyLocation' => $keyLocation, 'urlList' => array_values($urls),
            ]);
            $status = $r->status();
            $outcome = in_array($status, [200, 202], true) ? 'accepted' : 'rejected';
            $message = match ($status) {
                200, 202 => 'Accepted. Search engines will crawl these pages when they choose to; this does not guarantee indexing.',
                400 => 'Rejected: the request was not valid.',
                403 => 'Rejected: the key file could not be confirmed on your site.',
                422 => 'Rejected: some URLs are not on this site or the key does not match.',
                429 => 'Rejected: too many submissions. Try again later.',
                default => 'Rejected with HTTP '.$status.'.',
            };
        } catch (Throwable $e) {
            $outcome = 'error';
            $message = 'Could not reach IndexNow: '.$e->getMessage();
        }

        $submission = IndexNowSubmission::create([
            'workspace_id' => $workspaceId, 'business_id' => $businessId, 'submitted_by' => $userId, 'host' => $host,
            'url_count' => count($urls), 'urls' => array_values($urls), 'status_code' => $status, 'outcome' => $outcome, 'message' => $message,
        ]);
        $this->audit->log('indexnow.submitted', $submission, null, ['host' => $host, 'urls' => count($urls), 'outcome' => $outcome], $workspaceId);

        return $submission;
    }

    private function indexableUrls(Business $business, string $host): array
    {
        $run = CrawlRun::query()->where('business_id', $business->id)->where('status', 'completed')->latest('id')->first();
        if (! $run) {
            return [];
        }

        return CrawledPage::query()->where('crawl_run_id', $run->id)->where('status_code', 200)->whereNotNull('content_hash')->get()
            ->reject(fn ($p) => str_contains(strtolower(($p->robots_meta ?? '').' '.($p->x_robots_tag ?? '')), 'noindex') || ! empty($p->extras['blocked_by_robots']))
            ->map(fn ($p) => $p->final_url ?? $p->url)->all();
    }
}
