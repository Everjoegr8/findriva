<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActionStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActionTaskResource;
use App\Models\ActionTask;
use App\Models\Business;
use App\Models\WorkspaceMembership;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Action Centre: a prioritised list of what to do next, who does it, whether the
 * customer approved it, and whether a later check confirmed it.
 */
class ActionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Business $business): AnonymousResourceCollection
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $request->validate(['status' => ['nullable', 'string'], 'category' => ['nullable', 'string', 'max:30']]);

        $statuses = $request->filled('status') ? explode(',', $request->string('status')) : null;
        $q = $business->actions()
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->input('category'), fn ($q, $c) => $q->where('category', $c))
            ->with(['issue:id,code,severity,url,why_it_matters,verification_method,evidence', 'assignee:id,name'])
            ->orderByDesc('priority')->orderBy('id');

        return ActionTaskResource::collection($q->paginate(min(100, (int) $request->integer('per_page', 50))));
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureInWorkspace($business);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', Rule::in(['technical', 'content', 'identity', 'local', 'social', 'indexability'])],
            'priority' => ['nullable', 'integer', 'between:0,100'],
            'requires_approval' => ['nullable', 'boolean'],
            'due_at' => ['nullable', 'date'],
        ]);
        $action = ActionTask::create($data + [
            'business_id' => $business->id, 'created_by' => $request->user()->id, 'status' => ActionStatus::Open,
            'requires_approval' => $data['requires_approval'] ?? true,
        ]);
        $this->audit->log('action.created', $action, null, $data);

        return (new ActionTaskResource($action))->response()->setStatusCode(201);
    }

    public function update(Request $request, Business $business, ActionTask $actionTask): ActionTaskResource
    {
        $action = $actionTask;
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($action, $business);
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ActionStatus::class)],
            'assignee_id' => ['sometimes', 'nullable', 'integer'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $before = ['status' => $action->status->value, 'assignee_id' => $action->assignee_id];
        $changes = [];

        if (isset($data['status'])) {
            $next = ActionStatus::from($data['status']);
            if ($next === ActionStatus::Approved) {
                throw ValidationException::withMessages(['status' => 'Use the approve action to approve a change.']);
            }
            if ($next === ActionStatus::Verified) {
                throw ValidationException::withMessages(['status' => 'An action is verified by a re-check, not set by hand.']);
            }
            if ($next !== $action->status && ! in_array($next, $action->status->allowedNext(), true)) {
                throw ValidationException::withMessages(['status' => "An action cannot move from {$action->status->value} to {$next->value}."]);
            }
            // Work on the customer's channels cannot start before they approve it.
            if ($action->requires_approval && in_array($next, [ActionStatus::InProgress, ActionStatus::Done], true) && ! $action->approved_at) {
                throw ValidationException::withMessages(['status' => 'This change needs the business owner\'s approval first.']);
            }
            $changes['status'] = $next;
            if ($next === ActionStatus::Done) {
                $changes['completed_at'] = now();
            }
        }
        if (array_key_exists('assignee_id', $data)) {
            if ($data['assignee_id'] !== null && ! WorkspaceMembership::query()->where('workspace_id', $this->workspace()->id)->where('user_id', $data['assignee_id'])->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'The assignee must be a member of this workspace.']);
            }
            $changes['assignee_id'] = $data['assignee_id'];
        }
        if (array_key_exists('due_at', $data)) {
            $changes['due_at'] = $data['due_at'];
        }

        $action->update($changes);
        $this->audit->log('action.updated', $action, $before, array_map(fn ($v) => $v instanceof ActionStatus ? $v->value : $v, $changes) + ['note' => $data['note'] ?? null]);

        return new ActionTaskResource($action->fresh(['issue', 'assignee']));
    }

    public function requestApproval(Business $business, ActionTask $actionTask): ActionTaskResource
    {
        $action = $actionTask;
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($action, $business);
        if (! in_array(ActionStatus::AwaitingApproval, $action->status->allowedNext(), true)) {
            throw ValidationException::withMessages(['status' => 'This action cannot be sent for approval now.']);
        }
        $before = ['status' => $action->status->value];
        $action->update(['status' => ActionStatus::AwaitingApproval]);
        $this->audit->log('action.approval_requested', $action, $before, ['status' => 'awaiting_approval']);

        return new ActionTaskResource($action);
    }

    public function approve(Request $request, Business $business, ActionTask $actionTask): ActionTaskResource
    {
        $action = $actionTask;
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureBelongs($action, $business);
        $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);

        if ($action->status !== ActionStatus::AwaitingApproval) {
            throw ValidationException::withMessages(['status' => 'Only actions awaiting approval can be approved or rejected.']);
        }
        $before = ['status' => $action->status->value];
        if ($request->input('decision') === 'approve') {
            $action->update(['status' => ActionStatus::Approved, 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        } else {
            $action->update(['status' => ActionStatus::Open, 'approved_by' => null, 'approved_at' => null]);
        }
        $this->audit->log('action.'.$request->input('decision').'d', $action, $before, ['status' => $action->status->value, 'note' => $request->input('note')]);

        return new ActionTaskResource($action);
    }
}
