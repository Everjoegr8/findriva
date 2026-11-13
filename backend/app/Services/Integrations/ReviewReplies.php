<?php

namespace App\Services\Integrations;

use App\Models\Business;
use App\Models\ProfileReview;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Content\ClaimChecker;
use App\Services\Facts\BusinessFacts;
use Illuminate\Validation\ValidationException;

/**
 * Review replies: Findriva suggests a short, polite draft from the review itself (never invented
 * details); a person edits it; an owner or admin approves; only then is it posted to Google.
 */
class ReviewReplies
{
    public function __construct(
        private readonly GoogleBusinessProfile $google,
        private readonly BusinessFacts $facts,
        private readonly ClaimChecker $claims,
        private readonly AuditLogger $audit,
    ) {}

    public function suggest(ProfileReview $review, Business $business): string
    {
        $first = $review->reviewer_name ? trim(explode(' ', trim($review->reviewer_name))[0]) : null;
        $hi = $first ? "Hi {$first}," : 'Hello,';
        $name = $business->name;
        $rating = $review->rating ?? 0;
        $phone = $this->facts->for($business)['phones'][0] ?? null;

        return match (true) {
            $rating >= 4 => "{$hi} thank you for taking the time to review {$name}. We're glad you had a good experience and we look forward to seeing you again.",
            $rating === 3 => "{$hi} thank you for your feedback. We'd like to do better next time. If you're happy to share more, please get in touch with us directly".($phone ? " on {$phone}" : '').'.',
            default => "{$hi} we're sorry to hear about your experience, and thank you for telling us. We'd like to put this right. Please contact us directly".($phone ? " on {$phone}" : '').' so we can look into it.',
        };
    }

    /** @return array{flags: list<array<string, mixed>>, blocking: bool} */
    public function check(string $text, Business $business): array
    {
        $flags = $this->claims->check($text, $this->facts->for($business), 'review_reply');
        if (mb_strlen($text) > (int) config('findriva.business_profile.reply_max_length', 4000)) {
            $flags[] = ['severity' => 'block', 'kind' => 'too_long', 'text' => '', 'message' => 'Keep the reply under 4,000 characters.'];
        }

        return ['flags' => array_values($flags), 'blocking' => collect($flags)->contains('severity', 'block')];
    }

    public function saveDraft(ProfileReview $review, Business $business, User $user, string $text): ProfileReview
    {
        if (in_array($review->reply_status, ['awaiting_approval', 'posting'], true)) {
            throw ValidationException::withMessages(['reply' => 'This reply is waiting for approval. Ask an owner or admin to approve or send it back first.']);
        }
        $review->update(['reply_draft' => trim($text), 'reply_status' => 'draft', 'drafted_by' => $user->id, 'reply_error' => null, 'approved_by' => null, 'approved_at' => null]);

        return $review;
    }

    public function submit(ProfileReview $review, Business $business): ProfileReview
    {
        if ($review->reply_status !== 'draft' || ! filled($review->reply_draft)) {
            throw ValidationException::withMessages(['reply' => 'Write a reply first.']);
        }
        if ($this->check($review->reply_draft, $business)['blocking']) {
            throw ValidationException::withMessages(['reply' => 'Fix the flagged problems in the reply first.']);
        }
        $review->update(['reply_status' => 'awaiting_approval']);

        return $review;
    }

    public function reject(ProfileReview $review, User $user): ProfileReview
    {
        if ($review->reply_status !== 'awaiting_approval') {
            throw ValidationException::withMessages(['reply' => 'There is no reply waiting for approval.']);
        }
        $review->update(['reply_status' => 'draft']);
        $this->audit->log('review_reply.rejected', $review, null, ['by' => $user->id]);

        return $review;
    }

    /** Approve and post. The text that is posted is exactly the text that was approved. */
    public function approveAndPost(ProfileReview $review, Business $business, User $user): ProfileReview
    {
        if (! in_array($review->reply_status, ['awaiting_approval', 'failed'], true)) {
            throw ValidationException::withMessages(['reply' => 'Submit the reply for approval first.']);
        }
        if ($this->check((string) $review->reply_draft, $business)['blocking']) {
            throw ValidationException::withMessages(['reply' => 'Fix the flagged problems in the reply first.']);
        }
        $review->update(['reply_status' => 'posting', 'approved_by' => $user->id, 'approved_at' => now()]);
        $this->audit->log('review_reply.approved', $review, null, ['review' => $review->external_id, 'by' => $user->id]);

        try {
            $result = $this->google->reply($review->connection, $review->external_id, (string) $review->reply_draft);
        } catch (IntegrationException $e) {
            $review->update(['reply_status' => 'failed', 'reply_error' => $e->getMessage()]);
            $this->audit->log('review_reply.failed', $review, null, ['error' => $e->getMessage()]);

            return $review;
        }
        $review->update([
            'reply_status' => 'posted', 'posted_at' => now(), 'reply_error' => null,
            'reply_comment' => $result['comment'] ?? $review->reply_draft,
            'reply_updated_at' => isset($result['updateTime']) ? now()->parse($result['updateTime']) : now(),
        ]);
        $this->audit->log('review_reply.posted', $review, null, ['review' => $review->external_id, 'by' => $user->id]);

        return $review;
    }
}
