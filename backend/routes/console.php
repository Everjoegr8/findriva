<?php

use App\Jobs\CheckAiAnswers;
use App\Jobs\CheckRankings;
use App\Jobs\SyncBusinessProfile;
use App\Jobs\SyncSearchConsole;
use App\Models\AiQuestion;
use App\Models\IntegrationConnection;
use App\Models\Keyword;
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

// Nightly Google Business Profile import (profile facts, reviews, daily actions).
Schedule::call(function () {
    IntegrationConnection::withoutGlobalScopes()->where('provider', 'google_business_profile')
        ->where('status', 'connected')->whereNotNull('property')
        ->each(fn ($c) => SyncBusinessProfile::dispatch($c->workspace_id, $c->id));
})->dailyAt('04:45')->name('sync-business-profile')->onOneServer();

// Paid plans whose period ended (after the grace period) go back to Free.
Schedule::call(fn () => app(BillingService::class)->expireLapsed())->dailyAt('02:00')->name('expire-lapsed-plans')->onOneServer();

// Weekly live position check for tracked keywords (Mondays, before the working week starts in Lagos).
Schedule::call(function () {
    Keyword::withoutGlobalScopes()->where('status', 'tracked')->select('workspace_id', 'business_id')->distinct()
        ->get()->each(fn ($k) => CheckRankings::dispatch($k->workspace_id, $k->business_id, true));
})->weeklyOn(1, '05:00')->name('check-rankings')->onOneServer();

// Weekly AI answer check for each business's questions (Wednesdays).
Schedule::call(function () {
    AiQuestion::withoutGlobalScopes()->where('active', true)->select('workspace_id', 'business_id')->distinct()
        ->get()->each(fn ($q) => CheckAiAnswers::dispatch($q->workspace_id, $q->business_id, true));
})->weeklyOn(3, '05:00')->name('check-ai-answers')->onOneServer();
