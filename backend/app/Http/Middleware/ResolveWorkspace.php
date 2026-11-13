<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant from the X-Workspace-Id header (or the user's last workspace)
 * and refuses the request unless the user is a member. Runs before route model binding,
 * so bound models are already tenant-scoped.
 */
class ResolveWorkspace
{
    public function __construct(private readonly CurrentWorkspace $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $id = $request->header('X-Workspace-Id') ?: $user->current_workspace_id;
        if (! $id) {
            $id = WorkspaceMembership::query()->where('user_id', $user->id)->orderBy('id')->value('workspace_id');
        }

        $isMember = $id && ctype_digit((string) $id)
            && WorkspaceMembership::query()->where('workspace_id', $id)->where('user_id', $user->id)->exists();

        if (! $isMember) {
            return response()->json(['message' => 'You do not have access to this workspace.'], 403);
        }

        $workspace = Workspace::query()->findOrFail($id);
        $this->tenant->set($workspace);
        if ((int) $user->current_workspace_id !== $workspace->id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->saveQuietly();
        }

        try {
            return $next($request);
        } finally {
            $this->tenant->set(null);
        }
    }
}
