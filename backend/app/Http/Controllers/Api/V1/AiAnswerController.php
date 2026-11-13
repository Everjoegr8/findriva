<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Jobs\CheckAiAnswers;
use App\Models\AiAnswerCheck;
use App\Models\AiQuestion;
use App\Models\Business;
use App\Services\AiVisibility\AiVisibility;
use App\Services\AiVisibility\AnswerEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Whether an AI assistant names the business when customers ask it for what the business offers. */
class AiAnswerController extends Controller
{
    public function index(Business $business, AiVisibility $ai, AnswerEngine $engine): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $limits = $this->workspace()->entitlements();
        $allowance = (int) ($limits['ai_answer_questions'] ?? 0);
        $questions = AiQuestion::query()->where('business_id', $business->id)->orderByDesc('active')->orderBy('id')->get();
        $checks = AiAnswerCheck::query()->whereIn('ai_question_id', $questions->pluck('id'))
            ->where('checked_at', '>=', now()->subDays(120))->orderBy('checked_at')->get()->groupBy('ai_question_id');
        $activeIds = $questions->where('active', true)->pluck('id')->values();

        $rows = $questions->map(function (AiQuestion $q) use ($checks, $activeIds, $allowance) {
            $list = $checks->get($q->id, collect());
            $latest = $list->last();
            $rank = $activeIds->search($q->id);

            return [
                'id' => $q->id, 'question' => $q->question, 'active' => $q->active, 'keyword_id' => $q->keyword_id,
                'within_allowance' => $q->active && $rank !== false && $rank < $allowance,
                'latest' => $latest ? [
                    'checked_at' => $latest->checked_at->toIso8601String(), 'engine' => $latest->engine, 'model' => $latest->model,
                    'location' => $latest->location, 'named' => $latest->named, 'own_site_cited' => $latest->own_site_cited,
                    'excerpt' => $latest->excerpt, 'answer' => $latest->answer, 'sources' => $latest->sources ?? [],
                ] : null,
                'history' => $list->map(fn ($c) => ['date' => $c->checked_at->toDateString(), 'named' => $c->named])->values(),
            ];
        });

        $latest = $rows->pluck('latest')->filter();
        $cited = $latest->flatMap(fn ($l) => collect($l['sources'])->where('cited', true)->where('kind', '!=', 'own')->unique('domain'))
            ->groupBy('domain')->map(fn ($g, $d) => ['domain' => $d, 'kind' => $g->first()['kind'], 'answers' => $g->count()])
            ->sortByDesc('answers')->values()->take(15);

        return response()->json([
            'data' => $rows->values(),
            'summary' => [
                'questions' => $rows->where('active', true)->count(),
                'asked' => $latest->count(),
                'named' => $latest->where('named', true)->count(),
                'own_site_cited' => $latest->where('own_site_cited', true)->count(),
                'last_checked_at' => $latest->max('checked_at'),
            ],
            'often_cited' => $cited,
            'engine' => ['name' => $engine->name(), 'model' => $engine->model(), 'configured' => $engine->isConfigured()],
            'location' => $ai->location($business),
            'allowance' => $allowance,
            'cap_usd_per_day' => (float) ($limits['ai_answer_spend_usd_per_day'] ?? 0),
            'last_run' => Cache::get(AiVisibility::lastRunKey($business)),
        ]);
    }

    public function suggest(Business $business, AiVisibility $ai): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);

        return response()->json(['data' => ['added' => $ai->suggestQuestions($business)]]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureInWorkspace($business);
        $data = $request->validate(['question' => ['required', 'string', 'min:8', 'max:300']]);
        $question = trim(preg_replace('/\s+/', ' ', $data['question']));
        // The point is to see whether the assistant finds the business on its own.
        foreach (array_merge([$business->name], $business->aliases()->pluck('alias')->all()) as $name) {
            if (mb_strlen(trim($name)) >= 3 && mb_stripos($question, trim($name)) !== false) {
                throw ValidationException::withMessages(['question' => 'Leave your business name out, so the answer shows whether the assistant finds you on its own.']);
            }
        }
        $q = AiQuestion::query()->firstOrCreate(['business_id' => $business->id, 'question' => $question], ['active' => true]);
        $q->update(['active' => true]);

        return response()->json(['data' => ['id' => $q->id, 'question' => $q->question]], $q->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Business $business, AiQuestion $question): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($question, $business);
        $question->update($request->validate(['active' => ['required', 'boolean']]));

        return response()->json(['data' => ['id' => $question->id, 'active' => $question->active]]);
    }

    public function destroy(Business $business, AiQuestion $question): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($question, $business);
        $question->delete();

        return response()->json(null, 204);
    }

    public function check(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::AuditRun);
        $this->ensureInWorkspace($business);
        CheckAiAnswers::dispatch($business->workspace_id, $business->id, false);

        return response()->json(['data' => ['status' => 'queued']], 202);
    }
}
