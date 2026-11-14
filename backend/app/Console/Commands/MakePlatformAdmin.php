<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/** Grant or remove access to the operations console. Run on the server; there is no web route for this on purpose. */
class MakePlatformAdmin extends Command
{
    protected $signature = 'findriva:admin {email : The account must already exist (sign up first)} {--revoke : Remove operations access}';

    protected $description = 'Give an existing Findriva account access to the operations console, or take it away';

    public function handle(AuditLogger $audit): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user) {
            $this->error('No account with that email. Sign up on the website first, then run this again.');

            return self::FAILURE;
        }
        $grant = ! $this->option('revoke');
        $user->forceFill(['is_platform_admin' => $grant])->save();
        $audit->log($grant ? 'admin.granted' : 'admin.revoked', $user, null, ['email' => $user->email], $user->current_workspace_id);
        $this->info($grant ? "{$user->email} can now open Operations after signing in." : "{$user->email} no longer has operations access.");
        if ($grant && ! $user->mfa_enabled_at) {
            $this->warn('Ask them to turn on two-step sign-in in Account security: this account can see every customer.');
        }

        return self::SUCCESS;
    }
}
