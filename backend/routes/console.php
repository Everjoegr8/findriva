<?php

use App\Jobs\SyncSearchConsole;
use App\Models\IntegrationConnection;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly import of finished Search Console days for every connected business.
Schedule::call(function () {
    IntegrationConnection::withoutGlobalScopes()->where('provider', 'google_search_console')
        ->where('status', 'connected')->whereNotNull('property')
        ->each(fn ($c) => SyncSearchConsole::dispatch($c->workspace_id, $c->id));
})->dailyAt('04:30')->name('sync-search-console')->onOneServer();

// Paid plans whose period ended (after the grace period) go back to Free.
Schedule::call(fn () => app(BillingService::class)->expireLapsed())->dailyAt('02:00')->name('expire-lapsed-plans')->onOneServer();
