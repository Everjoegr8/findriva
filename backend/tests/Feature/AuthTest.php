<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_owner_workspace_and_token(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Obi', 'email' => 'Ada@Example.com', 'password' => 'secret-pass-123', 'workspace_name' => 'Glow',
        ])->assertCreated();

        $this->assertNotEmpty($res->json('token'));
        $this->assertSame('owner', $res->json('user.workspaces.0.role'));
        $this->assertSame('ada@example.com', $res->json('user.email'));

        $this->withToken($res->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.email', 'ada@example.com');
    }

    public function test_login_rejects_wrong_password_and_never_logs_credentials(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-123'])->assertCreated();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'secret-pass-123'])->assertOk();

        $logged = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('secret-pass-123', $logged);
        $this->assertStringNotContainsString($res->json('token'), $logged);
        $this->assertStringNotContainsString('password', $res->getContent());
    }

    public function test_weak_password_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'A', 'email' => 'b@example.com', 'password' => 'short'])->assertUnprocessable();
    }
}
