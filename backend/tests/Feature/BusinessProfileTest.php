<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\SyncBusinessProfile;
use App\Models\IntegrationConnection;
use App\Models\ProfileMetric;
use App\Models\ProfileReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Request> */
    private array $google = [];

    private bool $replyFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'cid', 'services.google.client_secret' => 'csecret',
            'services.google.redirect' => 'http://localhost/api/v1/integrations/google/callback',
            'findriva.app_url' => 'http://app.test',
        ]);
        $this->fakeGoogle();
    }

    private function fakeGoogle(): void
    {
        $end = now()->subDays(3);
        Http::fake(function (Request $r) use ($end) {
            $this->google[] = $r;
            $url = $r->url();
            if (str_starts_with($url, 'https://oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'scope' => 'openid email https://www.googleapis.com/auth/business.manage']);
            }
            if (str_starts_with($url, 'https://oauth2.googleapis.com/revoke')) {
                return Http::response([]);
            }
            if (str_contains($url, 'openidconnect')) {
                return Http::response(['email' => 'ada@gmail.example']);
            }
            if (str_starts_with($url, 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts')) {
                return Http::response(['accounts' => [['name' => 'accounts/1', 'accountName' => 'Ada']]]);
            }
            if (str_starts_with($url, 'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/1/locations')) {
                return Http::response(['locations' => [
                    ['name' => 'locations/8', 'title' => 'Another shop', 'phoneNumbers' => ['primaryPhone' => '0809 999 0000']],
                    ['name' => 'locations/9', 'title' => 'Glow by Ada', 'phoneNumbers' => ['primaryPhone' => '0803 123 4567'], 'websiteUri' => 'https://glow.example/'],
                ]]);
            }
            if (str_starts_with($url, 'https://mybusinessbusinessinformation.googleapis.com/v1/locations/9')) {
                return Http::response([
                    'name' => 'locations/9', 'title' => 'Glow By Ada Studio', 'phoneNumbers' => ['primaryPhone' => '0803 123 4567'],
                    'websiteUri' => 'https://old-glow.example/', 'categories' => ['primaryCategory' => ['displayName' => 'Make-up artist']],
                    'storefrontAddress' => ['addressLines' => ['12 Admiralty Way'], 'locality' => 'Lekki'], 'regularHours' => ['periods' => []],
                    'metadata' => ['mapsUri' => 'https://maps.google.com/?cid=9'],
                ]);
            }
            if (str_starts_with($url, 'https://mybusiness.googleapis.com/v4/accounts/1/locations/9/reviews/') && $r->method() === 'PUT') {
                return $this->replyFails ? Http::response(['error' => ['message' => 'nope']], 500)
                    : Http::response(['comment' => $r['comment'], 'updateTime' => now()->toIso8601String()]);
            }
            if (str_starts_with($url, 'https://mybusiness.googleapis.com/v4/accounts/1/locations/9/reviews')) {
                return Http::response(['reviews' => [
                    ['reviewId' => 'r1', 'reviewer' => ['displayName' => 'Chioma Okafor'], 'starRating' => 'FIVE', 'comment' => 'Beautiful bridal look!', 'createTime' => now()->subDays(5)->toIso8601String(), 'updateTime' => now()->subDays(5)->toIso8601String()],
                    ['reviewId' => 'r2', 'reviewer' => ['isAnonymous' => true, 'displayName' => 'A Google user'], 'starRating' => 'TWO', 'comment' => 'Late by an hour.', 'createTime' => now()->subDays(2)->toIso8601String()],
                    ['reviewId' => 'r3', 'reviewer' => ['displayName' => 'Bola'], 'starRating' => 'FOUR', 'createTime' => now()->subDays(40)->toIso8601String(),
                        'reviewReply' => ['comment' => 'Thanks Bola!', 'updateTime' => now()->subDays(39)->toIso8601String()]],
                ]]);
            }
            if (str_contains($url, 'businessprofileperformance.googleapis.com/v1/locations/9:fetchMultiDailyMetricsTimeSeries')) {
                $d = fn ($daysAgo) => ['year' => $end->copy()->subDays($daysAgo)->year, 'month' => $end->copy()->subDays($daysAgo)->month, 'day' => $end->copy()->subDays($daysAgo)->day];

                return Http::response(['multiDailyMetricTimeSeries' => [['dailyMetricTimeSeries' => [
                    ['dailyMetric' => 'CALL_CLICKS', 'timeSeries' => ['datedValues' => [['date' => $d(0), 'value' => '4'], ['date' => $d(1), 'value' => '2'], ['date' => $d(2)]]]],
                    ['dailyMetric' => 'BUSINESS_DIRECTION_REQUESTS', 'timeSeries' => ['datedValues' => [['date' => $d(0), 'value' => '3']]]],
                    ['dailyMetric' => 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'timeSeries' => ['datedValues' => [['date' => $d(0), 'value' => '120']]]],
                ]]]]);
            }

            return Http::response('unexpected '.$url, 500);
        });
    }

    private function business($user, $ws): int
    {
        return $this->as($user, $ws)->postJson('/api/v1/businesses', [
            'name' => 'Glow by Ada', 'primary_category' => 'Makeup artist', 'website_url' => 'https://glow.example', 'start_audit' => false,
            'contacts' => [['type' => 'phone', 'value' => '08031234567']],
        ])->assertCreated()->json('data.id');
    }

    private function connect($user, $ws, int $id): IntegrationConnection
    {
        $url = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/integrations/google-business/connect")->assertOk()->json('data.authorization_url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('https://www.googleapis.com/auth/business.manage openid email', $q['scope']);
        $this->get('/api/v1/integrations/google/callback?code=abc&state='.$q['state'])
            ->assertRedirect("http://app.test/businesses/{$id}/google-profile?connected=google_business_profile");

        return IntegrationConnection::withoutGlobalScopes()->where('business_id', $id)->where('provider', 'google_business_profile')->firstOrFail();
    }

    public function test_connect_matches_location_imports_profile_reviews_and_actions(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = $this->connect($owner, $ws, $id);

        $this->assertSame('accounts/1/locations/9', $c->property, 'matched by website and phone, not the first location');
        $this->assertSame('connected', $c->status);
        $this->assertNotNull($c->last_synced_at);

        $res = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/google-profile")->assertOk();
        $this->assertStringNotContainsString('refresh-1', $res->getContent());
        $diff = collect($res->json('data.differences'))->keyBy('field');
        $this->assertTrue($diff->has('name'));
        $this->assertTrue($diff->has('website'));
        $this->assertFalse($diff->has('phone'), 'same number in a different format is not a difference');
        $this->assertTrue($diff->has('hours'));

        $res->assertJsonPath('data.reviews.count', 3)
            ->assertJsonPath('data.reviews.unanswered', 2)
            ->assertJsonPath('data.reviews.average_rating', 3.67)
            ->assertJsonPath('data.metrics.totals.calls', 6)
            ->assertJsonPath('data.metrics.totals.directions', 3)
            ->assertJsonPath('data.metrics.totals.maps_views', 120)
            ->assertJsonPath('data.metrics.previous.calls', 0);
        // Days Google left out are stored as zero, so the range is complete.
        $this->assertSame(90 * 8, ProfileMetric::withoutGlobalScopes()->count());

        $reviews = collect($this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/reviews?filter=unanswered")->json('data'));
        $this->assertCount(2, $reviews);
        $this->assertNull($reviews->firstWhere('rating', 2)['reviewer_name'], 'anonymous reviewers stay anonymous');
        $this->assertSame('posted', ProfileReview::withoutGlobalScopes()->where('external_id', 'r3')->value('reply_status'));
    }

    public function test_reply_needs_owner_or_admin_approval_before_it_is_posted(): void
    {
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $id = $this->business($owner, $ws);
        $this->connect($owner, $ws, $id);
        $review = ProfileReview::withoutGlobalScopes()->where('external_id', 'r1')->firstOrFail();
        $low = ProfileReview::withoutGlobalScopes()->where('external_id', 'r2')->firstOrFail();

        $s = $this->as($editor, $ws)->getJson("/api/v1/businesses/{$id}/reviews/{$review->id}/suggestion")->assertOk();
        $this->assertStringStartsWith('Hi Chioma,', $s->json('data.text'));
        $this->assertStringContainsString('Glow by Ada', $s->json('data.text'));
        $lowText = $this->as($editor, $ws)->getJson("/api/v1/businesses/{$id}/reviews/{$low->id}/suggestion")->json('data.text');
        $this->assertStringStartsWith('Hello,', $lowText);
        $this->assertStringContainsString('sorry', $lowText);

        // A made-up phone number or a ranking promise blocks submission.
        $this->as($editor, $ws)->patchJson("/api/v1/businesses/{$id}/reviews/{$review->id}", ['reply' => 'Thanks! Call 0701 000 0000 and we guarantee top of Google.'])
            ->assertOk()->assertJsonPath('data.draft_check.blocking', true);
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/submit")->assertStatus(422);

        $text = 'Thank you Chioma! It was a joy doing your bridal look.';
        $this->as($editor, $ws)->patchJson("/api/v1/businesses/{$id}/reviews/{$review->id}", ['reply' => $text])->assertOk();
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/submit")->assertOk()->assertJsonPath('data.reply_status', 'awaiting_approval');

        // Editors cannot post to the customer's Google profile; nothing has been sent yet.
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->assertCount(0, array_filter($this->google, fn ($r) => $r->method() === 'PUT'));
        // While waiting, the draft cannot be changed under the approver.
        $this->as($editor, $ws)->patchJson("/api/v1/businesses/{$id}/reviews/{$review->id}", ['reply' => 'changed'])->assertStatus(422);

        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.reply_status', 'posted')->assertJsonPath('data.reply', $text);
        $puts = array_values(array_filter($this->google, fn ($r) => $r->method() === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame($text, $puts[0]['comment'], 'exactly the approved text is posted');
        $this->assertStringEndsWith('/accounts/1/locations/9/reviews/r1/reply', $puts[0]->url());
    }

    public function test_failed_post_is_reported_and_can_be_retried(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $this->connect($owner, $ws, $id);
        $review = ProfileReview::withoutGlobalScopes()->where('external_id', 'r2')->firstOrFail();

        $this->as($owner, $ws)->patchJson("/api/v1/businesses/{$id}/reviews/{$review->id}", ['reply' => 'We are sorry, and we would like to make this right.']);
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/submit")->assertOk();
        $this->replyFails = true;
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/decision", ['decision' => 'approve'])
            ->assertStatus(502)->assertJsonPath('data.reply_status', 'failed');
        $this->replyFails = false;
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/reviews/{$review->id}/decision", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.reply_status', 'posted');
    }

    public function test_choosing_another_location_resets_imported_data_and_isolation_holds(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $c = $this->connect($owner, $ws, $id);

        Queue::fake();
        $this->as($owner, $ws)->patchJson("/api/v1/businesses/{$id}/integrations/{$c->id}", ['property' => 'accounts/9/locations/1'])->assertStatus(422);
        $this->as($owner, $ws)->patchJson("/api/v1/businesses/{$id}/integrations/{$c->id}", ['property' => 'accounts/1/locations/8'])->assertOk();
        $this->assertSame(0, ProfileReview::withoutGlobalScopes()->count());
        $this->assertSame(0, ProfileMetric::withoutGlobalScopes()->count());
        Queue::assertPushed(SyncBusinessProfile::class);

        [$other, $otherWs] = $this->member();
        $this->as($other, $otherWs)->getJson("/api/v1/businesses/{$id}/google-profile")->assertNotFound();
        $this->as($other, $otherWs)->getJson("/api/v1/businesses/{$id}/reviews")->assertNotFound();
    }

    public function test_disconnecting_one_google_product_keeps_a_shared_grant(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $gbp = $this->connect($owner, $ws, $id);
        IntegrationConnection::withoutGlobalScopes()->create([
            'workspace_id' => $ws->id, 'business_id' => $id, 'provider' => 'google_search_console', 'status' => 'connected',
            'account_email' => 'ada@gmail.example', 'refresh_token' => 'refresh-1',
        ]);

        $this->as($owner, $ws)->deleteJson("/api/v1/businesses/{$id}/integrations/{$gbp->id}")->assertNoContent();
        $this->assertCount(0, array_filter($this->google, fn ($r) => str_contains($r->url(), '/revoke')));
        $this->assertSame(0, ProfileReview::withoutGlobalScopes()->count(), 'imported reviews go with the connection');
    }
}
