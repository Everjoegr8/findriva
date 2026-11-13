<?php

namespace App\Services\AiVisibility;

use App\Models\AiAnswerCheck;
use App\Models\AiQuestion;
use App\Models\Business;
use App\Models\Keyword;
use App\Models\UsageEvent;
use App\Services\AuditLogger;
use App\Services\Crawler\UrlNormalizer;
use App\Services\Facts\BusinessFacts;
use App\Services\Rankings\OwnPresence;
use App\Services\Rankings\Platforms;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Whether an AI assistant with web search names the business when customers ask about what it offers. */
class AiVisibility
{
    public function __construct(private readonly AnswerEngine $engine, private readonly BusinessFacts $facts, private readonly AuditLogger $audit) {}

    /** Questions from tracked keywords, phrased the way people ask assistants. Existing ones are kept. */
    public function suggestQuestions(Business $business): int
    {
        $added = 0;
        $keywords = Keyword::query()->where('business_id', $business->id)->where('status', 'tracked')
            ->whereNotIn('intent', ['navigational'])->orderByRaw('search_volume is null')->orderByDesc('search_volume')->limit(25)->get();
        foreach ($keywords as $k) {
            $q = AiQuestion::query()->firstOrCreate(
                ['business_id' => $business->id, 'question' => self::phrase($k->keyword)],
                ['keyword_id' => $k->id, 'active' => true],
            );
            $added += $q->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    public static function phrase(string $keyword): string
    {
        $k = trim(preg_replace('/\s+/', ' ', $keyword));
        if (preg_match('/^(who|what|where|which|how|is|are|can|best|recommend)\b/i', $k) || str_ends_with($k, '?')) {
            return rtrim(ucfirst($k), '?').'?';
        }

        return "Can you recommend a good {$k}?";
    }

    /** @return Collection<int, AiQuestion> active questions in allowance order (oldest first) */
    public function activeQuestions(Business $business): Collection
    {
        return AiQuestion::query()->where('business_id', $business->id)->where('active', true)->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    public function run(Business $business, bool $scheduled = false): array
    {
        $limits = $business->workspace->entitlements();
        $allowance = (int) ($limits['ai_answer_questions'] ?? 0);
        $cap = (float) ($limits['ai_answer_spend_usd_per_day'] ?? 0);
        $minAgeHours = $scheduled ? 7 * 24 - 6 : 20;
        $questions = $this->activeQuestions($business);
        $s = [
            'engine' => $this->engine->name(), 'questions' => $questions->count(), 'allowance' => $allowance,
            'over_allowance' => max(0, $questions->count() - $allowance), 'checked' => 0, 'skipped_recent' => 0,
            'cap_usd' => $cap, 'spend_today_usd' => $this->spentToday($business),
        ];

        $status = match (true) {
            $questions->isEmpty() => 'nothing_to_check',
            ! $this->engine->isConfigured() => 'not_configured',
            $allowance <= 0 || $cap <= 0 => 'plan_excludes',
            default => null,
        };

        if ($status === null) {
            $status = 'checked';
            $location = $this->location($business);
            foreach ($questions->take($allowance) as $q) {
                $last = AiAnswerCheck::query()->where('ai_question_id', $q->id)->max('checked_at');
                if ($last && now()->diffInHours($last, true) < $minAgeHours) {
                    $s['skipped_recent']++;

                    continue;
                }
                if ($cap + 1e-9 < $s['spend_today_usd'] + $this->engine->estimatedCost()) {
                    $status = 'budget_exhausted';
                    break;
                }
                try {
                    $answer = $this->engine->ask($q->question, $location);
                } catch (AnswerEngineException $e) {
                    Log::warning('answer engine failed', ['business_id' => $business->id, 'error' => $e->getMessage()]);
                    $status = 'engine_error';
                    break;
                }
                $this->record($business, $q, $answer, $location);
                $s['checked']++;
                $s['spend_today_usd'] += $answer['cost'];
            }
            if ($status === 'checked' && $s['checked'] === 0 && $s['skipped_recent'] > 0) {
                $status = 'up_to_date';
            }
        }

        $s['status'] = $status;
        $s['spend_today_usd'] = round($this->spentToday($business), 4);
        $s['message'] = match ($status) {
            'checked' => "Asked {$s['checked']} questions.".($s['over_allowance'] ? " {$s['over_allowance']} questions are beyond your plan's allowance and were not asked." : ''),
            'up_to_date' => 'Every question was asked recently. Answers refresh weekly; you can ask again tomorrow.',
            'nothing_to_check' => 'Add questions first, or create them from your tracked keywords.',
            'not_configured' => 'AI answers are not checked yet because no AI assistant is connected.',
            'plan_excludes' => 'Your plan does not include AI answer checks.',
            'budget_exhausted' => "Today's AI answer budget for this workspace is used up. Asked {$s['checked']} questions; the rest will be asked next time.",
            default => "The AI assistant had a problem. Asked {$s['checked']} questions; nothing else was charged.",
        };
        $s['finished_at'] = now()->toIso8601String();
        Cache::put(self::lastRunKey($business), $s, now()->addDays(14));
        if (! $scheduled || $s['checked'] > 0) {
            $this->audit->log('ai_answers.checked', $business, null, $s);
        }

        return $s;
    }

    public static function lastRunKey(Business $business): string
    {
        return 'ai-answers:last:'.$business->id;
    }

    /** @return array{city: ?string, country: string, country_name: string, timezone: ?string} */
    public function location(Business $business): array
    {
        $facts = $this->facts->for($business);
        $country = $business->country ?: 'NG';

        return [
            'city' => $facts['primary_location']['city'] ?? ($facts['cities'][0] ?? null),
            'country' => $country,
            'country_name' => $facts['country_name'] ?? $country,
            'timezone' => ['NG' => 'Africa/Lagos', 'GH' => 'Africa/Accra', 'KE' => 'Africa/Nairobi', 'ZA' => 'Africa/Johannesburg'][$country] ?? null,
        ];
    }

    private function record(Business $business, AiQuestion $q, array $answer, array $location): void
    {
        $own = OwnPresence::for($business);
        $names = array_values(array_filter(array_unique(array_map(fn ($n) => trim((string) $n), array_merge([$business->name], $this->facts->for($business)['aliases']))), fn ($n) => mb_strlen($n) >= 3));
        $excerpt = null;
        foreach ($names as $n) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($n, '/').'(?![\p{L}\p{N}])/iu', $answer['text'], $m, PREG_OFFSET_CAPTURE)) {
                $start = max(0, $m[0][1] - 120);
                $excerpt = trim(($start > 0 ? '…' : '').mb_strcut($answer['text'], $start, 320)).'…';
                break;
            }
        }
        $sources = array_map(function ($src) use ($own) {
            $domain = UrlNormalizer::host($src['url']);

            return $src + ['domain' => $domain, 'kind' => $own->owns($src['url']) ? 'own' : (Platforms::isPlatform($domain) ? 'platform' : 'other')];
        }, $answer['sources']);

        AiAnswerCheck::create([
            'business_id' => $business->id, 'ai_question_id' => $q->id, 'engine' => $this->engine->name(), 'model' => $this->engine->model(),
            'location' => implode(', ', array_filter([$location['city'], $location['country_name']])),
            'answer' => mb_substr($answer['text'], 0, 8000),
            'named' => $excerpt !== null,
            'own_site_cited' => collect($sources)->contains(fn ($x) => $x['kind'] === 'own' && $x['cited']),
            'excerpt' => $excerpt, 'sources' => array_slice($sources, 0, 40), 'searches' => $answer['searches'], 'cost' => $answer['cost'], 'checked_at' => now(),
        ]);
        UsageEvent::create([
            'workspace_id' => $business->workspace_id, 'provider' => $this->engine->name(), 'action' => 'ai_answer_check',
            'units' => 1, 'estimated_cost' => $answer['cost'], 'context' => ['business_id' => $business->id, 'question_id' => $q->id],
        ]);
    }

    private function spentToday(Business $business): float
    {
        return (float) UsageEvent::query()->withoutGlobalScopes()->where('workspace_id', $business->workspace_id)
            ->where('action', 'ai_answer_check')->where('created_at', '>=', now()->startOfDay())->sum('estimated_cost');
    }
}
