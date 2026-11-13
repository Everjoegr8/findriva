<?php

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawler\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    protected FakeHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        // Tests must never touch the real network.
        Http::preventStrayRequests();
        $this->dns = new FakeHostResolver;
        $this->app->instance(HostResolver::class, $this->dns);
    }

    /** @return array{0: User, 1: Workspace} */
    protected function member(WorkspaceRole $role = WorkspaceRole::Owner, ?Workspace $workspace = null, string $plan = 'growth'): array
    {
        $user = User::factory()->create();
        $workspace ??= Workspace::factory()->create(['plan' => $plan]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return [$user, $workspace];
    }

    /** Headers + auth for an API call as this user in this workspace. */
    protected function as(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
