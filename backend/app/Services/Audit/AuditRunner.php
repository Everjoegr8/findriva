<?php

namespace App\Services\Audit;

use App\Models\Business;
use App\Models\CrawledPage;
use App\Models\CrawlRun;
use App\Models\UsageEvent;
use App\Services\AuditLogger;
use App\Services\Crawler\SiteCrawler;
use App\Services\Identity\IdentityChecker;
use Throwable;

class AuditRunner
{
    public function __construct(
        private readonly SiteCrawler $crawler,
        private readonly WebsiteAuditRules $rules,
        private readonly IdentityChecker $identity,
        private readonly IssueRecorder $recorder,
        private readonly AuditLogger $audit,
    ) {}

    /** Full website audit for a crawl run (called from the queued job). */
    public function runWebsite(CrawlRun $run): CrawlRun
    {
        if ($run->status === 'cancelled') {
            return $run;
        }
        $run->update(['status' => 'running', 'started_at' => now()]);
        $business = Business::query()->with(['aliases', 'contacts'])->findOrFail($run->business_id);
        $maxPages = (int) ($run->settings['max_pages'] ?? $business->workspace->entitlements()['pages_per_crawl']);

        try {
            $facts = $this->crawler->crawl($run, $maxPages);
            $pages = CrawledPage::query()->where('crawl_run_id', $run->id)->orderBy('id')->get();
            $findings = $this->rules->evaluate($facts, $pages, $business);
            $stats = $this->recorder->record($business, $findings, $run);

            // Data minimisation: page text was only needed for the identity checks above.
            foreach ($pages as $p) {
                if (isset($p->extras['body_text_sample'])) {
                    $x = $p->extras;
                    unset($x['body_text_sample']);
                    $p->update(['extras' => $x]);
                }
            }

            $this->refreshIdentity($business);

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'pages_checked' => $pages->count(),
                'issues_found' => count($findings),
                'site_facts' => $facts + ['reconciliation' => $stats],
            ]);
            UsageEvent::create([
                'workspace_id' => $run->workspace_id, 'provider' => 'findriva_crawler', 'action' => 'website_crawl',
                'units' => $pages->count(), 'context' => ['crawl_run_id' => $run->id, 'business_id' => $business->id],
            ]);
            $this->audit->log('audit.completed', $run, null, ['pages' => $pages->count(), 'issues' => count($findings)]);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'The audit stopped unexpectedly. Our team has been notified.']);
        }

        return $run->fresh();
    }

    /** Identity-only checks; no website needed. Cheap, runs on every profile change. */
    public function refreshIdentity(Business $business): array
    {
        $business->unsetRelations();

        return $this->recorder->record($business, $this->identity->findings($business), null);
    }
}
