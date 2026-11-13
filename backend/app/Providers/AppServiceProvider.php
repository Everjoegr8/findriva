<?php

namespace App\Providers;

use App\Services\Crawler\DnsHostResolver;
use App\Services\Crawler\HostResolver;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(180)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('audits', fn (Request $r) => Limit::perHour(20)->by('ws:'.($r->header('X-Workspace-Id') ?: $r->user()?->id)));
    }
}
