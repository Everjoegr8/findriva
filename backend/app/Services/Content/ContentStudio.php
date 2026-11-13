<?php

namespace App\Services\Content;

use App\Models\Business;
use App\Models\ContentAsset;
use App\Models\ContentVersion;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Facts\BusinessFacts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drafts → review → approval → (optional) apply. Every edit is a new version, every draft is
 * claim-checked against the facts, and approval is an owner/admin decision.
 */
class ContentStudio
{
    public function __construct(
        private readonly BusinessFacts $facts,
        private readonly ContentGenerator $generator,
        private readonly TemplateContentGenerator $template,
        private readonly ClaimChecker $claims,
        private readonly AuditLogger $audit,
    ) {}

    public function aiAvailable(): bool
    {
        return $this->generator->name() !== 'template';
    }

    public function generate(Business $business, User $by, string $type, ?int $serviceId, bool $useAi, ?ContentAsset $into = null): ContentAsset
    {
        $limit = (int) ($business->workspace->entitlements()['content_generations_per_day'] ?? 5);
        $today = UsageEvent::query()->withoutGlobalScopes()->where('workspace_id', $business->workspace_id)
            ->where('action', 'content_generation')->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= $limit) {
            throw ValidationException::withMessages(['type' => "Your plan allows {$limit} drafts a day. Edit an existing draft or try again tomorrow."]);
        }

        $facts = $this->facts->for($business);
        $options = [];
        if ($type === 'service_page') {
            $service = collect($facts['services'])->firstWhere('id', $serviceId);
            if (! $service) {
                throw ValidationException::withMessages(['service_id' => 'Choose one of this business\'s services.']);
            }
            $options['service'] = $service;
        }

        $generator = $useAi && $this->aiAvailable() ? $this->generator : $this->template;
        try {
            $draft = $generator->generate($type, $facts, $options);
        } catch (ContentGenerationException $e) {
            throw ValidationException::withMessages(['generator' => $e->getMessage()]);
        }

        UsageEvent::create([
            'workspace_id' => $business->workspace_id, 'provider' => $generator->name(), 'action' => 'content_generation',
            'units' => 1, 'context' => ['business_id' => $business->id, 'type' => $type],
        ]);

        return DB::transaction(function () use ($business, $by, $type, $draft, $generator, $facts, $into) {
            $asset = $into ?? ContentAsset::create([
                'business_id' => $business->id, 'created_by' => $by->id, 'type' => $type, 'title' => $draft['title'],
                'status' => 'draft', 'current_version' => 0,
            ]);
            $this->addVersion($asset, $by, $draft['body'], $generator->name(), $facts, $draft['source_facts'], $draft['missing_facts']);
            $this->audit->log('content.generated', $asset, null, ['type' => $type, 'generator' => $generator->name(), 'version' => $asset->current_version]);

            return $asset;
        });
    }

    /** A human edit: becomes a new version and sends the asset back to draft. */
    public function edit(ContentAsset $asset, User $by, string $body, ?string $title = null): ContentAsset
    {
        $facts = $this->facts->for($asset->business);

        return DB::transaction(function () use ($asset, $by, $body, $title, $facts) {
            if ($title) {
                $asset->title = $title;
            }
            $previous = $asset->current;
            $this->addVersion($asset, $by, $body, 'human', $facts, $previous?->source_facts ?? [], []);
            $this->audit->log('content.edited', $asset, null, ['version' => $asset->current_version]);

            return $asset;
        });
    }

    public function submit(ContentAsset $asset): ContentAsset
    {
        if (! in_array($asset->status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'Only drafts can be sent for review.']);
        }
        $this->ensureNoBlockers($asset);
        $asset->update(['status' => 'in_review']);
        $this->audit->log('content.submitted', $asset, null, ['version' => $asset->current_version]);

        return $asset;
    }

    public function decide(ContentAsset $asset, User $by, string $decision, ?string $note): ContentAsset
    {
        if ($asset->status !== 'in_review') {
            throw ValidationException::withMessages(['decision' => 'Send this draft for review first.']);
        }
        if ($decision === 'approve') {
            $this->ensureNoBlockers($asset);
        }
        $asset->update([
            'status' => $decision === 'approve' ? 'approved' : 'rejected',
            'approved_version' => $decision === 'approve' ? $asset->current_version : $asset->approved_version,
            'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note,
        ]);
        $this->audit->log('content.'.($decision === 'approve' ? 'approved' : 'rejected'), $asset, null, ['version' => $asset->current_version, 'note' => $note]);

        return $asset;
    }

    /** Copies approved copy into the business record. Only for types that live in Findriva. */
    public function apply(ContentAsset $asset): ContentAsset
    {
        $target = ContentTypes::get($asset->type)['applies_to'];
        if ($asset->status !== 'approved' || ! $asset->approved_version) {
            throw ValidationException::withMessages(['status' => 'Only approved content can be used.']);
        }
        if ($target !== 'business.description') {
            throw ValidationException::withMessages(['type' => 'Copy this text and paste it where it is used. Findriva does not post to your channels for you.']);
        }
        $version = $asset->version($asset->approved_version);
        $business = $asset->business;
        $before = ['description' => $business->description];
        $business->update(['description' => $version->body]);
        $asset->update(['applied_at' => now()]);
        $this->audit->log('content.applied', $business, $before, ['description' => $version->body, 'content_asset_id' => $asset->id]);

        return $asset;
    }

    private function addVersion(ContentAsset $asset, User $by, string $body, string $generator, array $facts, array $sourceFacts, array $missing): ContentVersion
    {
        $n = $asset->current_version + 1;
        $version = ContentVersion::create([
            'workspace_id' => $asset->workspace_id, 'content_asset_id' => $asset->id, 'created_by' => $by->id,
            'version' => $n, 'body' => $body, 'generator' => $generator,
            'source_facts' => $sourceFacts, 'missing_facts' => $missing,
            'claim_flags' => $this->claims->check($body, $facts, $asset->type),
        ]);
        $asset->update(['current_version' => $n, 'status' => 'draft']);
        $asset->unsetRelation('current');

        return $version;
    }

    private function ensureNoBlockers(ContentAsset $asset): void
    {
        $current = $asset->current;
        $flags = $this->claims->check($current->body, $this->facts->for($asset->business), $asset->type);
        if ($flags !== $current->claim_flags) {
            // Facts may have changed since the draft was written; keep the stored check current.
            $current->update(['claim_flags' => $flags]);
        }
        if (ClaimChecker::blocks($flags)) {
            throw ValidationException::withMessages(['claims' => 'Fix the highlighted problems before this can be approved.']);
        }
    }
}
