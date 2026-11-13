<?php

namespace App\Services\Publishing;

use App\Models\Business;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Facts\BusinessFacts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Findriva public business page: a one-page website for businesses that only have
 * social profiles. Publishing is an owner/admin decision and must pass a quality gate,
 * so we never put a thin or wrong page in front of search engines.
 */
class PublicPage
{
    private const RESERVED = ['admin', 'api', 'app', 'login', 'register', 'sitemap', 'robots', 'findriva', 'support', 'help', 'www'];

    public function __construct(private readonly BusinessFacts $facts, private readonly AuditLogger $audit) {}

    /** @return array{ready: bool, checks: list<array{key: string, label: string, ok: bool}>} */
    public function gate(Business $business): array
    {
        $facts = $this->facts->for($business);
        $missing = $this->facts->missing($facts);
        $checks = [];
        foreach (['category', 'description', 'location', 'phone'] as $key) {
            $checks[] = ['key' => $key, 'label' => BusinessFacts::label($key), 'ok' => ! in_array($key, $missing, true)];
        }
        $checks[] = ['key' => 'offerings', 'label' => BusinessFacts::label('offerings'), 'ok' => ! in_array('offerings', $missing, true)];

        return ['ready' => collect($checks)->every(fn ($c) => $c['ok']), 'checks' => $checks];
    }

    public function publish(Business $business, User $by, ?string $slug = null): Business
    {
        $gate = $this->gate($business);
        if (! $gate['ready']) {
            throw ValidationException::withMessages([
                'business' => 'Add '.collect($gate['checks'])->reject(fn ($c) => $c['ok'])->pluck('label')->join(', ', ' and ').' before publishing.',
            ]);
        }
        $slug = $slug !== null ? Str::slug($slug) : ($business->public_slug ?: $this->suggestSlug($business));
        if ($slug === '' || in_array($slug, self::RESERVED, true) || mb_strlen($slug) < 3) {
            throw ValidationException::withMessages(['slug' => 'Choose a different page address.']);
        }
        $taken = Business::withoutGlobalScopes()->withTrashed()->where('public_slug', $slug)->whereKeyNot($business->id)->exists();
        if ($taken) {
            throw ValidationException::withMessages(['slug' => 'That page address is already taken.']);
        }

        $before = $business->only(['public_slug', 'is_published']);
        $business->update(['public_slug' => $slug, 'is_published' => true, 'published_at' => now(), 'published_by' => $by->id]);
        $this->audit->log('public_page.published', $business, $before, ['public_slug' => $slug, 'url' => self::url($business)]);

        return $business;
    }

    public function unpublish(Business $business): Business
    {
        $business->update(['is_published' => false]);
        $this->audit->log('public_page.unpublished', $business, ['is_published' => true], ['is_published' => false]);

        return $business;
    }

    public function suggestSlug(Business $business): string
    {
        $city = $business->locations()->orderByDesc('is_primary')->value('city');
        $base = Str::slug(Str::limit($business->name, 60, '').($city ? ' '.$city : '')) ?: 'business';
        $slug = $base;
        for ($i = 2; Business::withoutGlobalScopes()->withTrashed()->where('public_slug', $slug)->whereKeyNot($business->id)->exists() || in_array($slug, self::RESERVED, true); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public static function url(Business $business): ?string
    {
        return $business->public_slug ? rtrim(config('findriva.public_url'), '/').'/b/'.$business->public_slug : null;
    }
}
