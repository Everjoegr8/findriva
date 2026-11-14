<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingTransaction;
use App\Models\SupportNote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminOpsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Daily sign-ups, new workspaces and successful payments, for the trend chart. */
    public function trends(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 30), 7), 90);
        $from = now()->subDays($days - 1)->startOfDay();
        $count = fn ($q, string $col) => $q->where($col, '>=', $from)->pluck($col)
            ->countBy(fn ($d) => Carbon::parse($d)->toDateString());
        $users = $count(User::query(), 'created_at');
        $workspaces = $count(Workspace::query(), 'created_at');
        $paid = BillingTransaction::query()->withoutGlobalScopes()->where('status', 'success')->where('paid_at', '>=', $from)
            ->get(['paid_at', 'amount'])->groupBy(fn ($t) => $t->paid_at->toDateString())->map->sum('amount');
        $rows = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $k = $d->toDateString();
            $rows[] = ['date' => $k, 'signups' => $users[$k] ?? 0, 'workspaces' => $workspaces[$k] ?? 0, 'revenue' => (int) ($paid[$k] ?? 0)];
        }

        return response()->json(['data' => $rows]);
    }

    public function payments(Request $request): JsonResponse
    {
        $page = BillingTransaction::query()->withoutGlobalScopes()
            ->leftJoin('workspaces', 'workspaces.id', '=', 'billing_transactions.workspace_id')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('billing_transactions.status', $s))
            ->latest('billing_transactions.id')
            ->paginate(50, ['billing_transactions.id', 'billing_transactions.workspace_id', 'workspaces.name as workspace_name', 'reference', 'billing_transactions.plan', 'amount', 'currency', 'status', 'paid_at', 'billing_transactions.created_at']);

        return response()->json($page);
    }

    public function failedJobs(): JsonResponse
    {
        $rows = DB::table('failed_jobs')->latest('id')->limit(100)->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($j) => [
                'id' => $j->id, 'uuid' => $j->uuid, 'queue' => $j->queue, 'failed_at' => $j->failed_at,
                'job' => class_basename((string) (json_decode($j->payload, true)['displayName'] ?? 'Job')),
                // First line only: the full trace can contain request data.
                'error' => Str::limit(strtok((string) $j->exception, "\n") ?: '', 300),
            ]);

        return response()->json(['data' => $rows, 'total' => DB::table('failed_jobs')->count()]);
    }

    public function retryJob(string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit->log('admin.job_retried', null, null, ['uuid' => $uuid]);

        return response()->json(['message' => 'The job is back in the queue.']);
    }

    public function forgetJob(string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->delete(), 404);
        $this->audit->log('admin.job_forgotten', null, null, ['uuid' => $uuid]);

        return response()->json(null, 204);
    }

    public function addNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'], 'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        if (empty($data['workspace_id']) && empty($data['user_id'])) {
            throw ValidationException::withMessages(['body' => 'A note belongs to a workspace or a person.']);
        }
        $note = SupportNote::create([...$data, 'author_id' => $request->user()->id]);

        return response()->json(['data' => $note->load('author:id,name')], 201);
    }

    public function deleteNote(Request $request, SupportNote $note): JsonResponse
    {
        abort_unless($note->author_id === $request->user()->id, 403, 'Only the person who wrote a note can delete it.');
        $note->delete();

        return response()->json(null, 204);
    }
}
