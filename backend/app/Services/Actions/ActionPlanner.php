<?php

namespace App\Services\Actions;

use App\Enums\ActionStatus;
use App\Enums\Severity;
use App\Models\ActionTask;
use App\Models\AuditIssue;
use App\Services\Audit\IssueCatalog;

/** Turns issues into a prioritised to-do list in the Action Centre. */
class ActionPlanner
{
    public function ensureActionFor(AuditIssue $issue): ActionTask
    {
        $def = IssueCatalog::get($issue->code);
        $existing = ActionTask::query()->where('audit_issue_id', $issue->id)->first();
        $evidence = ['issue_code' => $issue->code, 'url' => $issue->url, 'summary' => $issue->evidence];

        if ($existing) {
            $existing->update(['evidence' => $evidence, 'priority' => self::priority($def['severity'], $def['effort'])]);

            return $existing;
        }

        return ActionTask::create([
            'workspace_id' => $issue->workspace_id,
            'business_id' => $issue->business_id,
            'audit_issue_id' => $issue->id,
            'title' => $def['title'],
            'description' => $def['fix'],
            'category' => $def['category'],
            'priority' => self::priority($def['severity'], $def['effort']),
            'impact' => match ($def['severity']) {
                Severity::Critical, Severity::High => 'high',
                Severity::Medium => 'medium',
                default => 'low',
            },
            'effort' => $def['effort'],
            'access_needed' => $def['access'],
            // Changes we make on a channel the customer controls always need their approval first.
            'requires_approval' => $def['access'] !== null,
            'status' => ActionStatus::Open,
            'evidence' => $evidence,
        ]);
    }

    /** Severity first, then quick wins before slow ones. */
    public static function priority(Severity $severity, string $effort): int
    {
        $bonus = ['low' => 10, 'medium' => 5, 'high' => 0][$effort] ?? 0;

        return min(100, (int) round($severity->weight() * 0.9) + $bonus);
    }
}
