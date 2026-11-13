<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\RunWebsiteAudit;
use App\Models\Business;
use App\Models\BusinessAlias;
use App\Models\CrawlRun;
use App\Models\Workspace;
use App\Services\Audit\AuditRunner;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedBusiness($user, $ws, array $extra = []): array
    {
        Queue::fake();
        $res = $this->as($user, $ws)->postJson('/api/v1/businesses', array_merge([
            'name' => 'Glow by Ada',
            'website_url' => 'https://glowbyada.example',
            'locations' => [['city' => 'Lagos', 'country' => 'NG']],
            'contacts' => [['type' => 'phone', 'value' => '08031234567', 'is_primary' => true]],
            'assets' => [['url' => 'https://instagram.com/glowbyada']],
        ], $extra))->assertCreated();

        return $res->json('data');
    }

    public function test_user_cannot_read_or_change_another_workspaces_business(): void
    {
        [$alice, $wsA] = $this->member();
        [$bob, $wsB] = $this->member();
        $biz = $this->seedBusiness($alice, $wsA);
        $id = $biz['id'];
        $assetId = $biz['assets'][0]['id'];

        // Bob, in his own workspace, cannot see Alice's business or any of its records.
        $this->as($bob, $wsB)->getJson("/api/v1/businesses/{$id}")->assertNotFound();
        $this->as($bob, $wsB)->patchJson("/api/v1/businesses/{$id}", ['name' => 'Hacked'])->assertNotFound();
        $this->as($bob, $wsB)->deleteJson("/api/v1/businesses/{$id}")->assertNotFound();
        foreach (['assets', 'issues', 'actions', 'audits', 'locations', 'contacts', 'overview'] as $path) {
            $this->as($bob, $wsB)->getJson("/api/v1/businesses/{$id}/{$path}")->assertNotFound();
        }
        $this->as($bob, $wsB)->deleteJson("/api/v1/businesses/{$id}/assets/{$assetId}")->assertNotFound();
        $this->as($bob, $wsB)->getJson('/api/v1/businesses')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('Glow by Ada', Business::withoutGlobalScopes()->find($id)->name);
    }

    public function test_user_cannot_switch_into_a_workspace_they_do_not_belong_to(): void
    {
        [$alice, $wsA] = $this->member();
        [$bob] = $this->member();
        $this->seedBusiness($alice, $wsA);

        $this->actingAs($bob, 'sanctum')->withHeader('X-Workspace-Id', (string) $wsA->id)
            ->getJson('/api/v1/businesses')->assertForbidden();
        $this->actingAs($bob, 'sanctum')->withHeader('X-Workspace-Id', 'abc')
            ->getJson('/api/v1/businesses')->assertForbidden();
    }

    public function test_child_record_ids_from_another_business_are_rejected(): void
    {
        [$alice, $wsA] = $this->member();
        $b1 = $this->seedBusiness($alice, $wsA);
        $b2 = $this->seedBusiness($alice, $wsA, ['name' => 'Second Biz', 'website_url' => null, 'assets' => []]);
        $locId = $b1['locations'][0]['id'];

        // Same tenant, wrong parent: still not found.
        $this->as($alice, $wsA)->patchJson("/api/v1/businesses/{$b2['id']}/locations/{$locId}", ['city' => 'Abuja'])->assertNotFound();
        $this->as($alice, $wsA)->postJson("/api/v1/businesses/{$b2['id']}/contacts", [
            'type' => 'phone', 'value' => '0801', 'business_location_id' => $locId,
        ])->assertUnprocessable();
    }

    public function test_global_scope_applies_inside_jobs(): void
    {
        [$alice, $wsA] = $this->member();
        [, $wsB] = $this->member();
        $biz = $this->seedBusiness($alice, $wsA);
        $run = CrawlRun::withoutGlobalScopes()->where('business_id', $biz['id'])->firstOrFail();

        // A job carrying the wrong tenant id cannot load the other tenant's crawl run.
        $this->expectException(ModelNotFoundException::class);
        (new RunWebsiteAudit($wsB->id, $run->id))->handle(app(CurrentWorkspace::class), app(AuditRunner::class));
    }

    public function test_cannot_create_rows_in_another_tenant_while_scoped(): void
    {
        [$alice, $wsA] = $this->member();
        [, $wsB] = $this->member();
        $biz = $this->seedBusiness($alice, $wsA);

        $this->expectException(\LogicException::class);
        app(CurrentWorkspace::class)->runAs(Workspace::find($wsB->id), function () use ($biz) {
            BusinessAlias::create(['business_id' => $biz['id'], 'alias' => 'x']);
        });
    }

    public function test_viewer_cannot_modify_and_editor_cannot_approve(): void
    {
        [$owner, $ws] = $this->member();
        [$viewer] = $this->member(WorkspaceRole::Viewer, $ws);
        [$editor] = $this->member(WorkspaceRole::Editor, $ws);
        $biz = $this->seedBusiness($owner, $ws, ['website_url' => null]);

        $this->as($viewer, $ws)->getJson("/api/v1/businesses/{$biz['id']}")->assertOk();
        $this->as($viewer, $ws)->patchJson("/api/v1/businesses/{$biz['id']}", ['name' => 'X'])->assertForbidden();
        $this->as($viewer, $ws)->postJson('/api/v1/businesses', ['name' => 'Y'])->assertForbidden();
        $this->as($editor, $ws)->deleteJson("/api/v1/businesses/{$biz['id']}")->assertForbidden();
        $this->as($editor, $ws)->postJson('/api/v1/workspace/members', ['email' => $viewer->email, 'role' => 'admin'])->assertForbidden();

        $action = $this->as($owner, $ws)->getJson("/api/v1/businesses/{$biz['id']}/actions")->json('data.0.id');
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$biz['id']}/actions/{$action}/request-approval")->assertOk();
        $this->as($editor, $ws)->postJson("/api/v1/businesses/{$biz['id']}/actions/{$action}/decision", ['decision' => 'approve'])->assertForbidden();
        $this->as($owner, $ws)->postJson("/api/v1/businesses/{$biz['id']}/actions/{$action}/decision", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        [$owner, $ws] = $this->member();
        $m = $ws->memberships()->where('user_id', $owner->id)->first();
        $this->as($owner, $ws)->patchJson("/api/v1/workspace/members/{$m->id}", ['role' => 'viewer'])->assertStatus(422);
    }

    public function test_admin_console_is_staff_only_and_never_returns_secrets(): void
    {
        [$user, $ws] = $this->member();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/stats')->assertForbidden();

        $user->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/stats')->assertOk()->assertJsonStructure(['data' => ['workspaces', 'crawl_runs']]);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertOk();
    }
}
