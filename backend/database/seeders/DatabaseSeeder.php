<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

/**
 * Local development data only. Everything created here is clearly labelled as demo data.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->firstOrCreate(['email' => 'demo@findriva.test'], [
            'name' => 'Demo Owner (sample data)', 'password' => 'demo-password-123',
        ]);
        $admin = User::query()->firstOrCreate(['email' => 'ops@findriva.test'], [
            'name' => 'Findriva Ops (sample data)', 'password' => 'demo-password-123',
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $workspace = Workspace::query()->firstOrCreate(['slug' => 'demo-workspace'], [
            'name' => 'Demo workspace (sample data)', 'plan' => 'growth', 'billing_status' => 'trial',
        ]);
        $workspace->memberships()->firstOrCreate(['user_id' => $owner->id], ['role' => WorkspaceRole::Owner]);
        $owner->forceFill(['current_workspace_id' => $workspace->id])->save();
    }
}
