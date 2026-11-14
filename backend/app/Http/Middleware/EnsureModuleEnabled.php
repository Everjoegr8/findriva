<?php

namespace App\Http\Middleware;

use App\Services\Platform\PlatformConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Refuses requests to a business section whose module staff switched off in Platform Control. */
class EnsureModuleEnabled
{
    /** First path segment after businesses/{business}/ => module key. */
    private const SECTIONS = [
        'keywords' => 'keywords',
        'rankings' => 'rankings',
        'competitors' => 'rankings',
        'local-grid' => 'map_grid',
        'ai-answers' => 'ai_answers',
        'ai-questions' => 'ai_answers',
        'content' => 'content_studio',
        'public-page' => 'public_pages',
        'wordpress' => 'wordpress',
        'google-profile' => 'google_profile',
        'reviews' => 'google_profile',
        'bing-performance' => 'bing',
    ];

    public function __construct(private readonly PlatformConfig $platform) {}

    public function handle(Request $request, Closure $next): Response
    {
        $section = $request->segment(5); // api / v1 / businesses / {id} / section
        $integration = $request->segment(6); // integrations/{google-business|bing}/connect
        $module = self::SECTIONS[$section] ?? match ($section === 'integrations' ? $integration : null) {
            'google-business' => 'google_profile',
            'bing' => 'bing',
            default => null,
        };
        if ($module && ! $this->platform->moduleOn($module)) {
            return response()->json([
                'message' => PlatformConfig::MODULES[$module][0].' is switched off on Findriva right now.',
                'module' => $module,
            ], 403);
        }

        return $next($request);
    }
}
