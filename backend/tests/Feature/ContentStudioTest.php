<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Business;
use App\Services\Content\ClaimChecker;
use App\Services\Facts\BusinessFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContentStudioTest extends TestCase
{
    use RefreshDatabase;

    private function business($user, $ws, array $extra = []): int
    {
        Queue::fake();

        return $this->as($user, $ws)->postJson('/api/v1/businesses', array_merge([
            'name' => 'Mama Put Kitchen',
            'primary_category' => 'Restaurant',
            'locations' => [['city' => 'Ibadan', 'location_type' => 'storefront', 'opening_hours' => ['mon' => ['08:00-20:00']]]],
            'contacts' => [['type' => 'phone', 'value' => '0805 555 1234', 'is_primary' => true]],
            'services' => [['name' => 'Office lunch delivery']],
            'products' => [['name' => 'Jollof rice', 'price' => 2500, 'currency' => 'NGN']],
        ], $extra))->json('data.id');
    }

    public function test_draft_review_approve_apply_flow_with_versions(): void
    {
        [$owner, $ws] = $this->member();
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $id = $this->business($owner, $ws);

        $draft = $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'business_description'])->assertCreated()->json('data');
        $this->assertSame('template', $draft['generator']);
        $this->assertSame('draft', $draft['status']);
        $this->assertStringContainsString('Mama Put Kitchen is a restaurant serving Ibadan.', $draft['body']);
        $this->assertStringContainsString('jollof rice', $draft['body']);
        $this->assertStringContainsString('+2348055551234', $draft['body']);
        $this->assertSame([], array_filter($draft['claim_flags'], fn ($f) => $f['severity'] === 'block'));

        // An edit that invents a number and a ranking promise cannot be sent for review.
        $bad = $draft['body']."\n\nWe have served 5,000 customers since 2012 and we guarantee you will rank #1 on Google. Call 0901 999 8888.";
        $edited = $this->as($editor, $ws)->patchJson("/api/v1/businesses/{$id}/content/{$draft['id']}", ['body' => $bad])->assertOk()->json('data');
        $this->assertSame(2, $edited['current_version']);
        $kinds = array_column($edited['claim_flags'], 'kind');
        $this->assertContains('ranking_promise', $kinds);
        $this->assertContains('unknown_phone', $kinds);
        $this->assertContains('unsupported_number', $kinds);
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/content/{$draft['id']}/submit")->assertUnprocessable()->assertJsonValidationErrors('claims');

        // Fix it, submit, and only an owner/admin can approve.
        $good = $draft['body']."\n\nOur Jollof rice is ₦2,500.";
        $this->as($editor, $ws)->patchJson("/api/v1/businesses/{$id}/content/{$draft['id']}", ['body' => $good])->assertOk();
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/content/{$draft['id']}/submit")->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->as($editor, $ws)->patchJson("/api/v1/businesses/{$id}/content/{$draft['id']}", ['body' => 'x'])->assertUnprocessable();
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$id}/content/{$draft['id']}/decision", ['decision' => 'approve'])->assertForbidden();
        $approved = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content/{$draft['id']}/decision", ['decision' => 'approve'])->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $this->assertSame(3, $approved['approved_version']);
        $this->assertCount(3, $approved['versions']);

        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content/{$draft['id']}/apply")->assertOk();
        $this->assertSame($good, Business::withoutGlobalScopes()->find($id)->description);
        $actions = array_column($this->as($owner, $ws)->getJson('/api/v1/workspace/activity')->json('data'), 'action');
        $this->assertContains('content.approved', $actions);
        $this->assertContains('content.applied', $actions);
    }

    public function test_missing_facts_become_placeholders_that_block_approval(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'New Shop'])->json('data.id');
        $draft = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'faq'])->assertCreated()->json('data');
        $this->assertStringContainsString('[ADD: business category]', $draft['body']);
        $this->assertContains('category', $draft['missing_facts']);
        $this->assertContains('placeholder', array_column($draft['claim_flags'], 'kind'));
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content/{$draft['id']}/submit")->assertUnprocessable();
    }

    public function test_service_page_needs_one_of_the_businesss_services_and_social_copy_is_never_auto_posted(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'service_page', 'service_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('service_id');
        $serviceId = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/services")->json('data.0.id');
        $page = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'service_page', 'service_id' => $serviceId])->assertCreated()->json('data');
        $this->assertSame('Office lunch delivery', $page['title']);

        $post = $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'social_post'])->json('data');
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content/{$post['id']}/submit")->assertOk();
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content/{$post['id']}/decision", ['decision' => 'approve'])->assertOk();
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content/{$post['id']}/apply")->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_daily_generation_limit_follows_the_plan(): void
    {
        [$owner, $ws] = $this->member(plan: 'free');
        $id = $this->as($owner, $ws)->postJson('/api/v1/businesses', ['name' => 'Tiny'])->json('data.id');
        for ($i = 0; $i < 5; $i++) {
            $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'short_bio'])->assertCreated();
        }
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'short_bio'])->assertUnprocessable();
    }

    public function test_ai_is_unavailable_without_a_key_and_falls_back_to_facts_only(): void
    {
        [$owner, $ws] = $this->member();
        $id = $this->business($owner, $ws);
        $this->assertFalse($this->as($owner, $ws)->getJson("/api/v1/businesses/{$id}/content")->json('ai_available'));
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$id}/content", ['type' => 'short_bio', 'use_ai' => true])
            ->assertCreated()->assertJsonPath('data.generator', 'template');
    }

    public function test_claim_checker_accepts_known_facts(): void
    {
        $facts = [
            'name' => 'X', 'phones' => ['+2348055551234'], 'whatsapp' => [], 'emails' => ['hi@x.ng'], 'products' => [['price' => 2500.0]],
            'website' => 'https://x.ng', 'profiles' => [], 'description' => null,
        ];
        $checker = new ClaimChecker;
        $this->assertSame([], $checker->check('Call 0805 555 1234 or email hi@x.ng. Jollof is N2,500. Open in 2024 hours.', $facts, 'social_post'));
        $flags = $checker->check('Email sales@other.com, visit https://evil.example, rice is ₦9,000.', $facts, 'social_post');
        $this->assertEqualsCanonicalizing(['unknown_email', 'unknown_link', 'unknown_price'], array_column($flags, 'kind'));
        $this->assertTrue(ClaimChecker::blocks($flags));
        $this->assertSame('opening hours', BusinessFacts::label('hours'));
    }
}
