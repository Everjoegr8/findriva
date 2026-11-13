<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\IntegrationConnection;
use App\Models\ProfileMetric;
use App\Models\ProfileReview;
use App\Services\Integrations\BusinessProfileImporter;
use App\Services\Integrations\GoogleBusinessProfile;
use App\Services\Integrations\ReviewReplies;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The connected Google Business Profile: how it compares, what people did on it, and its reviews. */
class BusinessProfileController extends Controller
{
    public function __construct(private readonly ReviewReplies $replies) {}

    public function show(Request $request, Business $business, BusinessProfileImporter $importer, GoogleBusinessProfile $google): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $days = (int) ($request->validate(['days' => ['nullable', Rule::in([7, 28, 90])]])['days'] ?? 28);
        $c = $this->connection($business);

        $metrics = null;
        if ($c?->data_through) {
            $end = CarbonImmutable::parse($c->data_through);
            $start = $end->subDays($days - 1);
            $sum = fn (CarbonImmutable $a, CarbonImmutable $b) => ProfileMetric::query()->where('integration_connection_id', $c->id)
                ->whereBetween('date', [$a->toDateString(), $b->toDateString()])
                ->selectRaw('metric, sum(value) as total, count(distinct date) as days')->groupBy('metric')->get()->keyBy('metric');
            $now = $sum($start, $end);
            $prev = $sum($start->subDays($days), $start->subDay());
            $total = fn ($rows, array $keys) => (int) collect($keys)->sum(fn ($k) => (int) ($rows[$k]->total ?? 0));
            $groups = [
                'calls' => ['CALL_CLICKS'], 'website_clicks' => ['WEBSITE_CLICKS'], 'directions' => ['BUSINESS_DIRECTION_REQUESTS'],
                'messages' => ['BUSINESS_CONVERSATIONS'],
                'search_views' => ['BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'],
                'maps_views' => ['BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'],
            ];
            $prevDays = (int) ($prev->max('days') ?? 0);
            $metrics = [
                'source' => 'Google Business Profile',
                'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'days' => $days],
                'totals' => collect($groups)->map(fn ($keys) => $total($now, $keys)),
                'previous' => $prevDays === $days ? collect($groups)->map(fn ($keys) => $total($prev, $keys)) : null,
                'daily' => ProfileMetric::query()->where('integration_connection_id', $c->id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->whereIn('metric', ['CALL_CLICKS', 'WEBSITE_CLICKS', 'BUSINESS_DIRECTION_REQUESTS'])
                    ->selectRaw('date, sum(value) as actions')->groupBy('date')->orderBy('date')->get()
                    ->map(fn ($r) => ['date' => CarbonImmutable::parse($r->date)->toDateString(), 'actions' => (int) $r->actions]),
            ];
        }

        $reviews = $c ? ProfileReview::query()->where('integration_connection_id', $c->id) : null;

        return response()->json(['data' => [
            'available' => $google->isConfigured(),
            'connection' => $c ? [
                'id' => $c->id, 'status' => $c->status, 'account_email' => $c->account_email, 'location' => $c->property,
                'available_locations' => $c->available_properties ?? [], 'last_synced_at' => $c->last_synced_at?->toIso8601String(),
                'data_through' => $c->data_through?->toDateString(), 'last_error' => $c->last_error,
            ] : null,
            'profile' => $c?->profile_snapshot,
            'differences' => $c ? $importer->differences($business, $c->profile_snapshot) : [],
            'metrics' => $metrics,
            'reviews' => $reviews ? [
                'count' => (clone $reviews)->count(),
                'rated' => (clone $reviews)->whereNotNull('rating')->count(),
                'average_rating' => ($avg = (clone $reviews)->whereNotNull('rating')->avg('rating')) !== null ? round((float) $avg, 2) : null,
                'unanswered' => (clone $reviews)->whereNull('reply_comment')->count(),
                'awaiting_approval' => (clone $reviews)->where('reply_status', 'awaiting_approval')->count(),
                'last_30_days' => (clone $reviews)->where('reviewed_at', '>=', now()->subDays(30))->count(),
            ] : null,
            'can_approve' => (bool) $request->user()?->canIn($this->workspace(), Permission::ActionApprove),
        ]]);
    }

    public function reviews(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $f = $request->validate(['filter' => ['nullable', Rule::in(['all', 'unanswered', 'awaiting_approval', 'low'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $c = $this->connection($business);
        if (! $c) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $page = ProfileReview::query()->where('integration_connection_id', $c->id)
            ->when(($f['filter'] ?? 'all') === 'unanswered', fn ($q) => $q->whereNull('reply_comment'))
            ->when(($f['filter'] ?? 'all') === 'awaiting_approval', fn ($q) => $q->where('reply_status', 'awaiting_approval'))
            ->when(($f['filter'] ?? 'all') === 'low', fn ($q) => $q->where('rating', '<=', 3))
            ->orderByDesc('reviewed_at')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (ProfileReview $r) => $this->present($r, $business)),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function suggestion(Business $business, ProfileReview $review): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureBelongs($review, $business);
        $text = $this->replies->suggest($review, $business);

        return response()->json(['data' => ['text' => $text] + $this->replies->check($text, $business)]);
    }

    public function saveReply(Request $request, Business $business, ProfileReview $review): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($review, $business);
        $data = $request->validate(['reply' => ['required', 'string', 'max:4096']]);
        $this->replies->saveDraft($review, $business, $request->user(), $data['reply']);

        return response()->json(['data' => $this->present($review->fresh(), $business)]);
    }

    public function submitReply(Business $business, ProfileReview $review): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($review, $business);
        $this->replies->submit($review, $business);

        return response()->json(['data' => $this->present($review->fresh(), $business)]);
    }

    public function decideReply(Request $request, Business $business, ProfileReview $review): JsonResponse
    {
        // Posting on the customer's Google profile is an owner/admin decision.
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureBelongs($review, $business);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        $data['decision'] === 'approve'
            ? $this->replies->approveAndPost($review, $business, $request->user())
            : $this->replies->reject($review, $request->user());
        $fresh = $review->fresh();

        return response()->json(['data' => $this->present($fresh, $business)], $fresh->reply_status === 'failed' ? 502 : 200);
    }

    private function present(ProfileReview $r, Business $business): array
    {
        return [
            'id' => $r->id, 'reviewer_name' => $r->reviewer_name, 'rating' => $r->rating, 'comment' => $r->comment,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'reply' => $r->reply_comment, 'reply_updated_at' => $r->reply_updated_at?->toIso8601String(),
            'reply_status' => $r->reply_status, 'reply_draft' => $r->reply_draft,
            'draft_check' => filled($r->reply_draft) ? $this->replies->check($r->reply_draft, $business) : null,
            'approved_at' => $r->approved_at?->toIso8601String(), 'posted_at' => $r->posted_at?->toIso8601String(),
            'reply_error' => $r->reply_error,
        ];
    }

    private function connection(Business $business): ?IntegrationConnection
    {
        return IntegrationConnection::query()->where('business_id', $business->id)->where('provider', 'google_business_profile')->first();
    }
}
