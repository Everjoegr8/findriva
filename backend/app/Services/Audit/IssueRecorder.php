<?php

namespace App\Services\Audit;

use App\Enums\ActionStatus;
use App\Models\AuditIssue;
use App\Models\Business;
use App\Models\CrawlRun;
use App\Services\Actions\ActionPlanner;
use Illuminate\Support\Facades\DB;

/**
 * Persists findings and reconciles them with what was open before. An open issue that is no
 * longer found is marked resolved, and its action is marked verified: this is the platform's
 * "validate" step, based on a fresh check, not on someone saying the fix was done.
 */
class IssueRecorder
{
    public function __construct(private readonly ActionPlanner $planner) {}

    /**
     * @param  list<array{code: string, url: ?string, evidence: array, crawled_page_id: ?int}>  $findings
     * @return array{created: int, updated: int, resolved: int}
     */
    public function record(Business $business, array $findings, ?CrawlRun $run): array
    {
        return DB::transaction(function () use ($business, $findings, $run) {
            $websiteScope = $run !== null;
            $open = AuditIssue::query()
                ->where('business_id', $business->id)
                ->where('status', 'open')
                ->when($websiteScope, fn ($q) => $q->whereNotNull('crawl_run_id'), fn ($q) => $q->whereNull('crawl_run_id'))
                ->get()
                ->keyBy('code');

            $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0];
            $seen = [];

            foreach ($findings as $f) {
                $seen[$f['code']] = true;
                $def = IssueCatalog::get($f['code']);
                $attrs = [
                    'crawl_run_id' => $run?->id,
                    'crawled_page_id' => $f['crawled_page_id'],
                    'url' => $f['url'],
                    'evidence' => $f['evidence'],
                    'category' => $def['category'],
                    'severity' => $def['severity'],
                    'title' => $def['title'],
                    'why_it_matters' => $def['why'],
                    'recommendation' => $def['fix'],
                    'verification_method' => $def['verify'],
                ];

                if ($existing = $open->get($f['code'])) {
                    $existing->update($attrs);
                    $stats['updated']++;
                    $issue = $existing;
                } else {
                    $issue = AuditIssue::create($attrs + [
                        'workspace_id' => $business->workspace_id,
                        'business_id' => $business->id,
                        'code' => $f['code'],
                        'status' => 'open',
                    ]);
                    $stats['created']++;
                }
                $this->planner->ensureActionFor($issue);
            }

            foreach ($open as $code => $issue) {
                if (isset($seen[$code])) {
                    continue;
                }
                $issue->update(['status' => 'resolved']);
                $stats['resolved']++;
                $action = $issue->action;
                if ($action && ! in_array($action->status, [ActionStatus::Dismissed, ActionStatus::Verified], true)) {
                    $action->update([
                        'status' => ActionStatus::Verified,
                        'verified_at' => now(),
                        'verification' => [
                            'method' => $issue->verification_method,
                            'result' => 'The issue was no longer found on re-check.',
                            'crawl_run_id' => $run?->id,
                            'checked_at' => now()->toIso8601String(),
                        ],
                    ]);
                }
            }

            return $stats;
        });
    }
}
