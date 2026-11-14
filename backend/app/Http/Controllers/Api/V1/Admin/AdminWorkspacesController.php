<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BillingTransaction;
use App\Models\Business;
use App\Models\SupportNote;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\BusinessService;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One customer workspace in full, for support. Integration tokens are never selected. */
class AdminWorkspacesController extends Controller
{
    public function show(Workspace $workspace): JsonResponse
    {
        $members = DB::table('workspace_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.workspace_id', $workspace->id)
            ->get(['u.id', 'u.name', 'u.email', 'm.role', 'u.email_verified_at', 'u.mfa_enabled_at', 'u.last_login_at', 'u.disabled_at']);

        $businesses = DB::table('businesses')->where('workspace_id', $workspace->id)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'name', 'primary_category', 'website_url', 'status', 'created_at'])
            ->map(function ($b) {
                $run = DB::table('crawl_runs')->where('business_id', $b->id)->latest('id')->first(['id', 'status', 'pages_checked', 'issues_found', 'error', 'created_at']);

                return [...(array) $b,
                    'last_audit' => $run,
                    'open_actions' => DB::table('action_tasks')->where('business_id', $b->id)->where('status', 'open')->count(),
                    'connections' => DB::table('integration_connections')->where('business_id', $b->id)
                        ->get(['provider', 'status', 'property', 'last_synced_at', 'data_through', 'last_error']),
                    'website_connector' => DB::table('site_connectors')->where('business_id', $b->id)->first(['platform', 'status', 'site_url', 'last_seen_at', 'published_at']),
                ];
            });

        $usage = DB::table('usage_events')->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->startOfMonth())
            ->groupBy('provider', 'action')->selectRaw('provider, action, SUM(units) as units, SUM(estimated_cost) as cost')->get()
            ->map(fn ($r) => ['provider' => $r->provider, 'action' => $r->action, 'units' => (int) $r->units, 'cost_usd' => round((float) $r->cost, 4)]);

        return response()->json(['data' => [
            'workspace' => [
                ...$workspace->only(['id', 'name', 'slug', 'type', 'plan', 'billing_status', 'billing_provider', 'cancel_at_period_end']),
                'current_period_end' => $workspace->current_period_end?->toIso8601String(),
                'created_at' => $workspace->created_at?->toIso8601String(),
            ],
            'members' => $members,
            'businesses' => $businesses,
            'usage_this_month' => $usage,
            'payments' => BillingTransaction::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->latest('id')->limit(20)
                ->get(['id', 'reference', 'plan', 'amount', 'currency', 'status', 'paid_at', 'created_at']),
            'activity' => AuditLog::query()->with('actor:id,name')->where('workspace_id', $workspace->id)->latest('id')->limit(40)
                ->get(['id', 'actor_id', 'action', 'target_type', 'target_id', 'created_at']),
            'notes' => SupportNote::query()->with('author:id,name')->where('workspace_id', $workspace->id)->latest('id')->get(),
        ]]);
    }

    /** Run a fresh website audit for a customer, for example after a failed one. Uses their plan's allowance like any audit. */
    public function runAudit(Request $request, Workspace $workspace, int $business, BusinessService $service, CurrentWorkspace $tenant, AuditLogger $audit): JsonResponse
    {
        $b = Business::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->findOrFail($business);
        $run = $tenant->runAs($workspace, function () use ($service, $b, $request, $audit) {
            $run = $service->startAudit($b, $request->user());
            $audit->log('admin.audit_started', $b, null, ['crawl_run_id' => $run->id]);

            return $run;
        });

        return response()->json(['data' => ['id' => $run->id, 'status' => $run->status]], 202);
    }
}
