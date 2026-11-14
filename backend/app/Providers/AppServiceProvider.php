<?php

namespace App\Providers;

use App\Services\AiVisibility\AnswerEngine;
use App\Services\AiVisibility\ClaudeAnswerEngine;
use App\Services\AiVisibility\NullAnswerEngine;
use App\Services\Content\ClaudeContentGenerator;
use App\Services\Content\ContentGenerator;
use App\Services\Content\TemplateContentGenerator;
use App\Services\Crawler\DnsHostResolver;
use App\Services\Crawler\HostResolver;
use App\Services\Keywords\DataForSeoKeywordProvider;
use App\Services\Keywords\KeywordResearchProvider;
use App\Services\Keywords\NullKeywordProvider;
use App\Services\Platform\PlatformConfig;
use App\Services\Rankings\DataForSeoMapsProvider;
use App\Services\Rankings\DataForSeoSerpProvider;
use App\Services\Rankings\MapsProvider;
use App\Services\Rankings\NullMapsProvider;
use App\Services\Rankings\NullSerpProvider;
use App\Services\Rankings\SerpProvider;
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

        $this->app->bind(KeywordResearchProvider::class, fn () => match (config('findriva.keywords.provider')) {
            'dataforseo' => new DataForSeoKeywordProvider((array) config('findriva.keywords.dataforseo')),
            default => new NullKeywordProvider,
        });
        $this->app->bind(SerpProvider::class, fn () => match (config('findriva.rankings.provider')) {
            'dataforseo' => new DataForSeoSerpProvider((array) config('findriva.keywords.dataforseo') + ['estimated_cost_per_check' => config('findriva.rankings.estimated_cost_per_check')]),
            default => new NullSerpProvider,
        });
        $this->app->bind(MapsProvider::class, fn () => match (config('findriva.rankings.provider')) {
            'dataforseo' => new DataForSeoMapsProvider((array) config('findriva.keywords.dataforseo') + ['estimated_cost_per_point' => config('findriva.rankings.estimated_cost_per_map_point')]),
            default => new NullMapsProvider,
        });
        // Which AI model each feature uses is chosen in Platform Control; the API key stays in the server environment.
        $this->app->bind(AnswerEngine::class, function ($app) {
            $ai = $app->make(PlatformConfig::class)->ai();
            if (! $ai['answers_enabled'] || blank(config('services.anthropic.key'))) {
                return new NullAnswerEngine;
            }
            $m = PlatformConfig::MODELS[$ai['answers_model']];

            return new ClaudeAnswerEngine((string) config('services.anthropic.key'), [
                'model' => $ai['answers_model'], 'input_usd_per_mtok' => $m['input'], 'output_usd_per_mtok' => $m['output'],
            ] + (array) config('findriva.ai_answers'));
        });
        $this->app->bind(ContentGenerator::class, function ($app) {
            $ai = $app->make(PlatformConfig::class)->ai();

            return $ai['content_engine'] === 'claude' && filled(config('services.anthropic.key'))
                ? new ClaudeContentGenerator((string) config('services.anthropic.key'), $ai['content_model'])
                : $app->make(TemplateContentGenerator::class);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(180)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('public', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        RateLimiter::for('research', fn (Request $r) => Limit::perHour(30)->by('ws:'.($r->header('X-Workspace-Id') ?: $r->user()?->id)));
        RateLimiter::for('generate', fn (Request $r) => Limit::perHour(60)->by('ws:'.($r->header('X-Workspace-Id') ?: $r->user()?->id)));
        RateLimiter::for('audits', fn (Request $r) => Limit::perHour(20)->by('ws:'.($r->header('X-Workspace-Id') ?: $r->user()?->id)));
    }
}
