<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditIssueResource;
use App\Http\Resources\CrawlRunResource;
use App\Models\AuditIssue;
use App\Models\Business;
use App\Models\CrawlRun;
use App\Services\AuditLogger;
use App\Services\BusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AuditController extends Controller
{
    public function __construct(private readonly BusinessService $service, private readonly AuditLogger $audit) {}

    public function index(Business $business): AnonymousResourceCollection
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);

        return CrawlRunResource::collection($business->crawlRuns()->latest('id')->paginate(20));
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureInWorkspace($business);
        $run = $this->service->startAudit($business, $request->user());

        return (new CrawlRunResource($run->fresh()))->response()->setStatusCode(202);
    }

    public function show(Business $business, CrawlRun $run): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureBelongs($run, $business);
        $run->loadCount('pages');

        return response()->json([
            'data' => new CrawlRunResource($run),
            'pages' => $run->pages()->select(['id', 'url', 'final_url', 'status_code', 'depth', 'title', 'indexable', 'response_ms'])
                ->orderBy('depth')->orderBy('id')->limit(200)->get(),
        ]);
    }

    public function cancel(Business $business, CrawlRun $run): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureBelongs($run, $business);
        if ($run->status !== 'queued') {
            abort(422, 'Only a queued audit can be cancelled.');
        }
        $run->update(['status' => 'cancelled', 'finished_at' => now()]);
        $this->audit->log('audit.cancelled', $run);

        return response()->json(['data' => new CrawlRunResource($run)]);
    }

    public function issues(Request $request, Business $business): AnonymousResourceCollection
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $request->validate([
            'status' => ['nullable', Rule::in(['open', 'resolved', 'dismissed', 'all'])],
            'category' => ['nullable', 'string', 'max:30'],
            'severity' => ['nullable', Rule::in(['critical', 'high', 'medium', 'low', 'info'])],
        ]);

        $q = $business->issues()
            ->when($request->input('status', 'open') !== 'all', fn ($q) => $q->where('status', $request->input('status', 'open')))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->input('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->with('action:id,audit_issue_id,status')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('id');

        return AuditIssueResource::collection($q->paginate(min(100, (int) $request->integer('per_page', 50))));
    }

    public function updateIssue(Request $request, Business $business, AuditIssue $issue): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($issue, $business);
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'dismissed'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $before = ['status' => $issue->status];
        $issue->update(['status' => $data['status']]);
        $this->audit->log('issue.'.$data['status'], $issue, $before, $data);

        return response()->json(['data' => new AuditIssueResource($issue)]);
    }
}
