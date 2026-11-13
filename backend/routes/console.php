<?php

use App\Jobs\SyncSearchConsole;
use App\Models\IntegrationConnection;
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
