<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Platform Control and customer workspaces stay separate: every staff route is guarded, with no exceptions. */
class PlatformSeparationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<RoutingRoute> */
    private function controlRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/admin')));
    }

    public function test_every_platform_control_route_requires_staff_and_sign_in(): void
    {
        $routes = $this->controlRoutes();
        $this->assertNotEmpty($routes);
        foreach ($routes as $r) {
            $mw = $r->gatherMiddleware();
            $this->assertContains('platform.admin', $mw, "{$r->uri()} is missing the staff guard");
            $this->assertTrue(collect($mw)->contains(fn ($m) => str_starts_with((string) $m, 'auth:sanctum')), "{$r->uri()} is missing sign-in");
        }
    }

    public function test_a_workspace_owner_is_refused_on_every_platform_control_route(): void
    {
        $owner = User::factory()->create();
        foreach ($this->controlRoutes() as $r) {
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $r->uri());
            foreach (array_diff($r->methods(), ['HEAD']) as $method) {
                $this->actingAs($owner, 'sanctum')->json($method, $uri)->assertForbidden();
            }
        }
    }

    public function test_signed_out_visitors_are_refused(): void
    {
        $this->getJson('/api/v1/admin/stats')->assertUnauthorized();
    }
}
