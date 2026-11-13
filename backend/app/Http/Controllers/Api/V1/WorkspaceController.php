<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Workspaces the user belongs to (no tenant needed). */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->workspaces()->withCount('businesses')->get()->map(fn ($w) => [
            'id' => $w->id, 'name' => $w->name, 'type' => $w->type, 'plan' => $w->plan,
            'role' => $w->pivot->role, 'businesses_count' => $w->businesses_count,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'in:business,agency'],
        ]);
        $workspace = DB::transaction(function () use ($data, $request) {
            $w = Workspace::create(['name' => $data['name'], 'type' => $data['type'] ?? 'business']);
            $w->memberships()->create(['user_id' => $request->user()->id, 'role' => WorkspaceRole::Owner]);

            return $w;
        });
        $this->audit->log('workspace.created', $workspace, null, $workspace->only('name', 'type'), $workspace->id);

        return response()->json(['data' => $workspace], 201);
    }

    public function show(): JsonResponse
    {
        $w = $this->workspace();

        return response()->json(['data' => $w->only('id', 'name', 'slug', 'type', 'plan', 'billing_status', 'settings') + [
            'role' => request()->user()->roleIn($w)?->value,
            'permissions' => array_map(fn ($p) => $p->value, request()->user()->roleIn($w)?->permissions() ?? []),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizePermission(Permission::WorkspaceUpdate);
        $w = $this->workspace();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'settings' => ['sometimes', 'array'],
        ]);
        $before = $w->only(array_keys($data));
        $w->update($data);
        $this->audit->log('workspace.updated', $w, $before, $data);

        return response()->json(['data' => $w]);
    }

    public function members(): JsonResponse
    {
        return response()->json(['data' => $this->workspace()->memberships()->with('user:id,name,email')->get()]);
    }

    public function addMember(Request $request): JsonResponse
    {
        $this->authorizePermission(Permission::MembersManage);
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', Rule::in(['admin', 'editor', 'viewer'])],
        ]);
        $user = User::query()->where('email', strtolower($data['email']))->firstOrFail();
        $m = WorkspaceMembership::query()->firstOrCreate(
            ['workspace_id' => $this->workspace()->id, 'user_id' => $user->id],
            ['role' => $data['role']],
        );
        $this->audit->log('member.added', $m, null, ['user_id' => $user->id, 'role' => $data['role']]);

        return response()->json(['data' => $m->load('user:id,name,email')], 201);
    }

    public function updateMember(Request $request, WorkspaceMembership $membership): JsonResponse
    {
        $this->authorizePermission(Permission::MembersManage);
        $this->ensureInWorkspace($membership);
        $data = $request->validate(['role' => ['required', Rule::in(['owner', 'admin', 'editor', 'viewer'])]]);

        $actorRole = $request->user()->roleIn($this->workspace());
        if ($data['role'] === 'owner' && $actorRole !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can make someone an owner.');
        }
        if ($membership->role === WorkspaceRole::Owner && $actorRole !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can change another owner.');
        }
        $this->guardLastOwner($membership, $data['role']);

        $before = ['role' => $membership->role->value];
        $membership->update(['role' => $data['role']]);
        $this->audit->log('member.role_changed', $membership, $before, $data);

        return response()->json(['data' => $membership]);
    }

    public function removeMember(Request $request, WorkspaceMembership $membership): JsonResponse
    {
        $this->authorizePermission(Permission::MembersManage);
        $this->ensureInWorkspace($membership);
        if ($membership->role === WorkspaceRole::Owner && $request->user()->roleIn($this->workspace()) !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can remove an owner.');
        }
        $this->guardLastOwner($membership, null);
        $membership->delete();
        $this->audit->log('member.removed', $membership, ['user_id' => $membership->user_id], null);

        return response()->json(null, 204);
    }

    /** The workspace's own activity log (who changed what). */
    public function activity(Request $request): JsonResponse
    {
        $this->authorizePermission(Permission::View);

        return response()->json(AuditLog::query()
            ->where('workspace_id', $this->workspace()->id)
            ->with('actor:id,name')
            ->latest('id')
            ->paginate(50, ['id', 'actor_id', 'action', 'target_type', 'target_id', 'after', 'created_at']));
    }

    private function guardLastOwner(WorkspaceMembership $membership, ?string $newRole): void
    {
        if ($membership->role !== WorkspaceRole::Owner || $newRole === 'owner') {
            return;
        }
        $owners = WorkspaceMembership::query()->where('workspace_id', $membership->workspace_id)->where('role', 'owner')->count();
        if ($owners <= 1) {
            abort(422, 'A workspace must keep at least one owner.');
        }
    }
}
