<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\ActionTask;
use App\Models\AiAnswerCheck;
use App\Models\AiQuestion;
use App\Models\AuditIssue;
use App\Models\Business;
use App\Models\DigitalAsset;
use App\Models\Keyword;
use App\Models\ProfileReview;
use App\Models\RankObservation;
use App\Models\SearchPerformance;
use App\Services\AuditLogger;
use App\Services\Platform\PlatformConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV downloads of a business's own records, exactly as stored. Opens in Excel and Google Sheets.
 * Empty cells mean "not measured", never zero.
 */
class ExportController extends Controller
{
    public const TYPES = ['actions', 'issues', 'keywords', 'rankings', 'reviews', 'profiles', 'search', 'ai-answers'];

    private const MODULES = ['keywords' => 'keywords', 'rankings' => 'rankings', 'reviews' => 'google_profile', 'ai-answers' => 'ai_answers'];

    public function __construct(private readonly AuditLogger $audit, private readonly PlatformConfig $platform) {}

    public function show(Business $business, string $type): StreamedResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        abort_unless(in_array($type, self::TYPES, true), 404);
        if (isset(self::MODULES[$type]) && ! $this->platform->moduleOn(self::MODULES[$type])) {
            abort(403, 'This feature is switched off on Findriva right now.');
        }

        [$header, $rows] = $this->{Str::camel($type)}($business);
        $this->audit->log('export.downloaded', $business, null, ['type' => $type, 'rows' => $rows->count()]);
        $name = Str::slug($business->name).'-'.$type.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte order mark so Excel shows ₦ and accents correctly
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Neutralise spreadsheet formulas (CSV injection): a cell starting with = + - @ is stored as text. */
    public static function cell(mixed $v): string
    {
        $s = match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'yes' : 'no',
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i'),
            default => (string) $v,
        };

        return preg_match('/^[=+\-@\t\r]/', $s) && ! is_numeric($s) ? "'".$s : $s;
    }

    private function actions(Business $b): array
    {
        return [['Title', 'Category', 'Status', 'Priority', 'Impact', 'Effort', 'Needs approval', 'Approved', 'Done', 'Verified by re-check', 'Created'],
            ActionTask::query()->where('business_id', $b->id)->orderBy('priority')->get()->map(fn ($a) => [
                $a->title, $a->category, $a->status instanceof \BackedEnum ? $a->status->value : $a->status, $a->priority, $a->impact, $a->effort,
                (bool) $a->requires_approval, $a->approved_at, $a->completed_at, $a->verified_at, $a->created_at,
            ])];
    }

    private function issues(Business $b): array
    {
        return [['Severity', 'Category', 'Issue', 'Page', 'Status', 'What to do', 'Found'],
            AuditIssue::query()->where('business_id', $b->id)->latest('id')->get()->map(fn ($i) => [
                $i->severity instanceof \BackedEnum ? $i->severity->value : $i->severity, $i->category, $i->title, $i->url, $i->status, $i->recommendation, $i->created_at,
            ])];
    }

    private function keywords(Business $b): array
    {
        return [['Keyword', 'Intent', 'Status', 'Monthly searches', 'Competition', 'Cost per click', 'Currency', 'Data source', 'Measured on'],
            Keyword::query()->where('business_id', $b->id)->orderBy('keyword')->get()->map(fn ($k) => [
                $k->keyword, $k->intent, $k->status, $k->search_volume, $k->competition, $k->cpc, $k->cpc_currency, $k->metrics_provider, $k->metrics_retrieved_at,
            ])];
    }

    private function rankings(Business $b): array
    {
        return [['Checked', 'Keyword', 'Position', 'Results checked', 'Your page', 'Device', 'Search engine', 'Source'],
            RankObservation::query()->with('keyword:id,keyword')->where('business_id', $b->id)->latest('checked_at')->limit(5000)->get()->map(fn ($r) => [
                $r->checked_at, $r->keyword?->keyword, $r->position ?? 'not in results', $r->depth, $r->url, $r->device, $r->search_engine, $r->provider,
            ])];
    }

    private function reviews(Business $b): array
    {
        return [['Date', 'Reviewer', 'Rating', 'Review', 'Reply status', 'Reply'],
            ProfileReview::query()->where('business_id', $b->id)->latest('reviewed_at')->get()->map(fn ($r) => [
                $r->reviewed_at, $r->reviewer_name, $r->rating, $r->comment, $r->reply_status, $r->reply_comment,
            ])];
    }

    private function profiles(Business $b): array
    {
        return [['Platform', 'Link', 'Handle', 'Ownership', 'Public check', 'Last checked'],
            DigitalAsset::query()->where('business_id', $b->id)->orderBy('platform')->get()->map(fn ($a) => [
                $a->platform instanceof \BackedEnum ? $a->platform->value : $a->platform, $a->url, $a->handle, $a->ownership_status, $a->scan_status, $a->last_scanned_at,
            ])];
    }

    private function search(Business $b): array
    {
        return [['Date', 'Source', 'Clicks', 'Impressions', 'Click rate', 'Average position'],
            SearchPerformance::query()->where('business_id', $b->id)->where('grain', 'total')->orderByDesc('date')->get()->map(fn ($r) => [
                $r->date, $r->source, $r->clicks, $r->impressions, round((float) $r->ctr, 4), round((float) $r->position, 1),
            ])];
    }

    private function aiAnswers(Business $b): array
    {
        /** @var Collection<int, string> $questions */
        $questions = AiQuestion::query()->where('business_id', $b->id)->pluck('question', 'id');

        return [['Checked', 'Question', 'Model', 'Business named', 'Own site cited', 'Excerpt'],
            AiAnswerCheck::query()->where('business_id', $b->id)->latest('checked_at')->limit(5000)->get()->map(fn ($c) => [
                $c->checked_at, $questions[$c->ai_question_id] ?? '', $c->model, (bool) $c->named, (bool) $c->own_site_cited, $c->excerpt,
            ])];
    }
}
