<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\UsageEvent;
use Illuminate\Http\JsonResponse;

/** What the workspace's plan covers and how much has been used. Coverage is scope, never ranking. */
class CoverageController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $w = $this->workspace();
        $limits = $w->entitlements();

        return response()->json(['data' => [
            'plan' => $w->plan,
            'label' => $limits['label'] ?? $w->plan,
            'limits' => array_diff_key($limits, ['label' => 1]),
            'usage' => [
                'businesses' => $w->businesses()->count(),
                'crawls_this_month' => UsageEvent::query()->where('action', 'website_crawl')->where('created_at', '>=', now()->startOfMonth())->count(),
                'pages_crawled_this_month' => (int) UsageEvent::query()->where('action', 'website_crawl')->where('created_at', '>=', now()->startOfMonth())->sum('units'),
            ],
        ]]);
    }
}
