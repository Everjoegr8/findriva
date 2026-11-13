<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionCentreTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_on_customer_channels_need_approval_and_verified_cannot_be_set_by_hand(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'X'])->json('data.id');
        $a = $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions", [
            'title' => 'Update homepage title', 'requires_approval' => true,
        ])->assertCreated()->json('data');

        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$a['id']}", ['status' => 'in_progress'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$a['id']}", ['status' => 'verified'])
            ->assertUnprocessable();
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$a['id']}", ['status' => 'approved'])
            ->assertUnprocessable();

        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions/{$a['id']}/request-approval")->assertOk();
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions/{$a['id']}/decision", ['decision' => 'reject'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions/{$a['id']}/request-approval")->assertOk();
        $this->as($user, $ws)->postJson("/api/v1/businesses/{$id}/actions/{$a['id']}/decision", ['decision' => 'approve'])->assertOk();
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$a['id']}", ['status' => 'in_progress'])->assertOk();
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$a['id']}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');

        $log = $this->as($user, $ws)->getJson('/api/v1/workspace/activity')->json('data');
        $this->assertContains('action.approved', array_column($log, 'action'));
    }

    public function test_actions_are_prioritised_by_severity(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'Y'])->json('data.id');
        $priorities = array_column($this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/actions")->json('data'), 'priority');
        $sorted = $priorities;
        rsort($sorted);
        $this->assertSame($sorted, $priorities);
    }

    public function test_assignee_must_be_workspace_member(): void
    {
        [$user, $ws] = $this->member();
        [$stranger] = $this->member();
        $id = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'Z'])->json('data.id');
        $actionId = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/actions")->json('data.0.id');
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$actionId}", ['assignee_id' => $stranger->id])->assertUnprocessable();
        $this->as($user, $ws)->patchJson("/api/v1/businesses/{$id}/actions/{$actionId}", ['assignee_id' => $user->id])->assertOk();
    }

    public function test_overview_keeps_measures_separate_and_never_invents_search_data(): void
    {
        [$user, $ws] = $this->member();
        $id = $this->as($user, $ws)->postJson('/api/v1/businesses', ['name' => 'Ov'])->json('data.id');
        $o = $this->as($user, $ws)->getJson("/api/v1/businesses/{$id}/overview")->assertOk()->json('data');
        $this->assertArrayHasKey('identity_completeness', $o);
        $this->assertNull($o['website']);
        $this->assertSame('not_connected', $o['search_engine_data']['search_console']['status']);
        $this->assertSame('not_started', $o['search_engine_data']['rank_tracking']['status']);
    }
}
