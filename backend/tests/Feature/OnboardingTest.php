<?php

namespace Tests\Feature;

use App\Models\CrawlRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_with_no_website_can_onboard_with_just_a_name(): void
    {
        [$user, $ws] = $this->member();
        $res = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'Mama Put Kitchen'])->assertCreated();

        $res->assertJsonPath('data.has_website', false);
        $this->assertSame(10, $res->json('identity.completeness.score'));
        $this->assertSame(0, CrawlRun::withoutGlobalScopes()->count());

        $codes = collect($this->as($user, $ws)->getJson('/api/v1/businesses/'.$res->json('data.id').'/issues')->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('identity_no_presence'));
        $this->assertTrue($codes->contains('identity_missing_location'));
        $this->assertTrue($codes->contains('identity_missing_phone'));
        // Every issue is actionable and explained.
        $this->as($user, $ws)->getJson('/api/v1/businesses/'.$res->json('data.id').'/actions')->assertJsonCount($codes->count(), 'data');
    }

    public function test_social_only_business_with_multiple_profiles_and_locations(): void
    {
        Queue::fake();
        [$user, $ws] = $this->member();
        $res = $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada',
            'primary_category' => 'Makeup artist',
            'description' => 'Bridal and event makeup artist serving Lekki, Ajah and Victoria Island in Lagos.',
            'locations' => [
                ['label' => 'Lekki studio', 'city' => 'Lagos', 'region' => 'Lagos', 'location_type' => 'storefront', 'opening_hours' => ['mon' => ['09:00-18:00']]],
                ['label' => 'Home service', 'city' => 'Lagos', 'location_type' => 'service_area', 'service_areas' => ['Ajah', 'Ikoyi'], 'opening_hours' => ['sat' => ['08:00-20:00']]],
            ],
            'contacts' => [['type' => 'whatsapp', 'value' => '0803 123 4567', 'is_primary' => true]],
            'services' => [['name' => 'Bridal makeup']],
            'assets' => [
                ['url' => 'https://www.instagram.com/glowbyada/'],
                ['url' => 'https://www.tiktok.com/@glowbyada'],
                ['url' => 'https://wa.me/2348031234567'],
            ],
        ])->assertCreated();

        $platforms = collect($res->json('data.assets'))->pluck('platform')->sort()->values()->all();
        $this->assertSame(['instagram', 'tiktok', 'whatsapp'], $platforms);
        $this->assertCount(2, $res->json('data.locations'));
        $this->assertTrue($res->json('data.locations.0.is_primary'));
        $this->assertSame('+2348031234567', $res->json('data.contacts.0.normalized_value'));
        $this->assertSame('glowbyada', collect($res->json('data.assets'))->firstWhere('platform', 'instagram')['handle']);
        $this->assertSame(0, CrawlRun::withoutGlobalScopes()->count(), 'No website means no crawl');

        $codes = collect($this->as($user, $ws)->getJson('/api/v1/businesses/'.$res->json('data.id').'/issues')->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('identity_no_website'));
        $this->assertTrue($codes->contains('google_profile_missing'));
        $this->assertFalse($codes->contains('identity_missing_phone'));
    }

    public function test_conflicting_details_are_flagged_for_review(): void
    {
        [$user, $ws] = $this->member();
        $res = $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Double Phone Ltd',
            'country' => 'NG',
            'locations' => [['city' => 'Accra', 'country' => 'GH']],
            'contacts' => [
                ['type' => 'phone', 'value' => '08031111111', 'is_primary' => true],
                ['type' => 'phone', 'value' => '08032222222', 'is_primary' => true],
            ],
        ])->assertCreated();

        $fields = collect($res->json('identity.conflicts'))->pluck('field');
        $this->assertTrue($fields->contains('phone'));
        $this->assertTrue($fields->contains('country'));
        $codes = collect($this->as($user, $ws)->getJson('/api/v1/businesses/'.$res->json('data.id').'/issues')->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('identity_conflict'));
    }

    public function test_fixing_identity_resolves_issue_and_verifies_action(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'Fixit'])->json('data.id');

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/contacts", ['type' => 'phone', 'value' => '08030000000'])->assertCreated();

        $issues = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/issues?status=all")->json('data'));
        $this->assertSame('resolved', $issues->firstWhere('code', 'identity_missing_phone')['status']);
        $actions = collect($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/actions?status=verified")->json('data'));
        $this->assertCount(1, $actions);
        $this->assertNotNull($actions[0]['verified_at']);
    }

    public function test_plan_limits_are_enforced(): void
    {
        [$user, $ws] = $this->member(plan: 'free');
        $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'One'])->assertCreated();
        $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'Two'])->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_audit_needs_a_website(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'No Site'])->json('data.id');
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/audits")->assertUnprocessable()->assertJsonValidationErrors('website_url');
    }

    public function test_invalid_input_is_rejected(): void
    {
        [$user, $ws] = $this->member();
        $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Bad', 'website_url' => 'javascript:alert(1)', 'assets' => [['url' => 'ftp://x.com']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['website_url', 'assets.0.url']);
    }
}
