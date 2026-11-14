<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrawlRun;
use App\Models\UsageEvent;
use App\Models\User;
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
        $prices = config('findriva.billing.prices', []);
        $paying = Workspace::query()->whereIn('billing_status', ['active', 'past_due'])->where('plan', '!=', 'free')
            ->selectRaw('plan, COUNT(*) as n')->groupBy('plan')->pluck('n', 'plan');

        return response()->json(['data' => [
            'users' => [
                'total' => User::count(),
                'new_7d' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'new_30d' => User::query()->where('created_at', '>=', now()->subDays(30))->count(),
                'unverified' => User::query()->whereNull('email_verified_at')->count(),
                'active_7d' => User::query()->where('last_login_at', '>=', now()->subDays(7))->count(),
                'disabled' => User::query()->whereNotNull('disabled_at')->count(),
            ],
            'plans' => Workspace::query()->selectRaw('plan, COUNT(*) as n')->groupBy('plan')->pluck('n', 'plan'),
            'paying_workspaces' => (int) $paying->sum(),
            // Monthly recurring revenue in kobo from paying workspaces at list price.
            'mrr' => (int) $paying->map(fn ($n, $plan) => $n * ($prices[$plan] ?? 0))->sum(),
            'revenue_30d' => (int) DB::table('billing_transactions')->where('status', 'success')->where('paid_at', '>=', now()->subDays(30))->sum('amount'),
            'connections' => DB::table('integration_connections')->selectRaw('provider, status, COUNT(*) as n')->groupBy('provider', 'status')->get(),
            'website_connectors' => DB::table('site_connectors')->where('status', 'connected')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
            'ai_spend_30d_usd' => round((float) UsageEvent::withoutGlobalScopes()->where('created_at', '>=', now()->subDays(30))->sum('estimated_cost'), 2),
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
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('id', ctype_digit($s) ? (int) $s : 0)
                ->orWhereIn('id', DB::table('workspace_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('u.email', 'like', '%'.strtolower($s).'%')->select('m.workspace_id'))))
            ->when($request->string('plan')->toString(), fn ($q, $p) => $q->where('plan', $p))
            ->latest('id')->paginate(50);

        return response()->json($page);
    }

    public function updateWorkspace(Request $request, Workspace $workspace, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', Rule::in(array_keys(config('findriva.plans')))],
            'billing_status' => ['sometimes', Rule::in(['trial', 'active', 'past_due', 'cancelled'])],
            // Give a customer paid access until a date, for example after a payment problem or as a goodwill gesture.
            'current_period_end' => ['sometimes', 'nullable', 'date', 'after:today'],
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
