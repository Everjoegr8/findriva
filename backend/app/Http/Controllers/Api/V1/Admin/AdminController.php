<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrawlRun;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Internal operations console. Platform staff see operational data across tenants, but
 * never integration secrets; there is deliberately no endpoint that returns them.
 */
class AdminController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json(['data' => [
            'workspaces' => Workspace::count(),
            'businesses' => DB::table('businesses')->whereNull('deleted_at')->count(),
            'businesses_without_website' => DB::table('businesses')->whereNull('deleted_at')->whereNull('website_url')->count(),
            'crawl_runs' => [
                'queued' => CrawlRun::withoutGlobalScopes()->where('status', 'queued')->count(),
                'running' => CrawlRun::withoutGlobalScopes()->where('status', 'running')->count(),
                'failed_7d' => CrawlRun::withoutGlobalScopes()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
                'completed_7d' => CrawlRun::withoutGlobalScopes()->where('status', 'completed')->where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'pages_crawled_30d' => (int) UsageEvent::withoutGlobalScopes()->where('action', 'website_crawl')->where('created_at', '>=', now()->subDays(30))->sum('units'),
        ]]);
    }

    public function workspaces(Request $request): JsonResponse
    {
        $page = Workspace::query()->withCount(['businesses', 'memberships'])
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->latest('id')->paginate(50);

        return response()->json($page);
    }

    public function updateWorkspace(Request $request, Workspace $workspace, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', Rule::in(array_keys(config('findriva.plans')))],
            'billing_status' => ['sometimes', Rule::in(['trial', 'active', 'past_due', 'cancelled'])],
        ]);
        $before = $workspace->only(array_keys($data));
        $workspace->update($data);
        $audit->log('admin.workspace_updated', $workspace, $before, $data, $workspace->id);

        return response()->json(['data' => $workspace]);
    }

    public function crawlRuns(Request $request): JsonResponse
    {
        return response()->json(CrawlRun::withoutGlobalScopes()
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(50, ['id', 'workspace_id', 'business_id', 'start_url', 'status', 'pages_checked', 'issues_found', 'error', 'created_at', 'finished_at']));
    }

    public function auditLogs(Request $request): JsonResponse
    {
        return response()->json(AuditLog::query()
            ->when($request->integer('workspace_id'), fn ($q, $id) => $q->where('workspace_id', $id))
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->with('actor:id,name,email')->latest('id')->paginate(100));
    }
}
