<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ContentAsset;
use App\Models\ContentVersion;
use App\Services\Content\ContentStudio;
use App\Services\Content\ContentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function __construct(private readonly ContentStudio $studio) {}

    public function index(Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureInWorkspace($business);
        $assets = ContentAsset::query()->where('business_id', $business->id)->with('current')->latest('updated_at')->get();

        return response()->json([
            'data' => $assets->map(fn ($a) => $this->present($a, false)),
            'types' => collect(ContentTypes::TYPES)->map(fn ($t, $k) => ['type' => $k, 'label' => $t['label'], 'use' => $t['use'], 'max_chars' => $t['max_chars'], 'needs' => $t['needs'] ?? null, 'can_apply' => $t['applies_to'] !== null])->values(),
            'ai_available' => $this->studio->aiAvailable(),
        ]);
    }

    public function show(Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::View);
        $this->ensureBelongs($content, $business);

        return response()->json(['data' => $this->present($content, true)]);
    }

    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureInWorkspace($business);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ContentTypes::TYPES))],
            'service_id' => ['nullable', 'integer'],
            'use_ai' => ['nullable', 'boolean'],
        ]);
        $asset = $this->studio->generate($business, $request->user(), $data['type'], $data['service_id'] ?? null, (bool) ($data['use_ai'] ?? false));

        return response()->json(['data' => $this->present($asset->fresh(), true)], 201);
    }

    public function update(Request $request, Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($content, $business);
        $data = $request->validate(['body' => ['required', 'string', 'max:8000'], 'title' => ['nullable', 'string', 'max:200']]);
        if ($content->status === 'in_review') {
            abort(422, 'This draft is waiting for review. Ask the reviewer to reject it before editing.');
        }
        $this->studio->edit($content, $request->user(), $data['body'], $data['title'] ?? null);

        return response()->json(['data' => $this->present($content->fresh(), true)]);
    }

    public function regenerate(Request $request, Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($content, $business);
        $data = $request->validate(['use_ai' => ['nullable', 'boolean'], 'service_id' => ['nullable', 'integer']]);
        if ($content->status === 'in_review') {
            abort(422, 'This draft is waiting for review.');
        }
        $this->studio->generate($business, $request->user(), $content->type, $data['service_id'] ?? null, (bool) ($data['use_ai'] ?? false), $content);

        return response()->json(['data' => $this->present($content->fresh(), true)]);
    }

    public function submit(Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($content, $business);
        $this->studio->submit($content);

        return response()->json(['data' => $this->present($content->fresh(), true)]);
    }

    public function decide(Request $request, Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::ActionApprove);
        $this->ensureBelongs($content, $business);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->studio->decide($content, $request->user(), $data['decision'], $data['note'] ?? null);

        return response()->json(['data' => $this->present($content->fresh(), true)]);
    }

    public function apply(Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::BusinessUpdate);
        $this->ensureBelongs($content, $business);
        $this->studio->apply($content);

        return response()->json(['data' => $this->present($content->fresh(), true)]);
    }

    public function destroy(Business $business, ContentAsset $content): JsonResponse
    {
        $this->authorizePermission(Permission::ActionUpdate);
        $this->ensureBelongs($content, $business);
        $content->delete();

        return response()->json(null, 204);
    }

    private function present(ContentAsset $a, bool $withVersions): array
    {
        $current = $a->current;
        $spec = ContentTypes::get($a->type);
        $out = [
            'id' => $a->id, 'type' => $a->type, 'type_label' => $spec['label'], 'use' => $spec['use'], 'max_chars' => $spec['max_chars'],
            'can_apply' => $spec['applies_to'] !== null, 'title' => $a->title, 'status' => $a->status,
            'current_version' => $a->current_version, 'approved_version' => $a->approved_version,
            'review_note' => $a->review_note, 'reviewed_at' => $a->reviewed_at?->toIso8601String(), 'applied_at' => $a->applied_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
            'body' => $current?->body, 'generator' => $current?->generator,
            'claim_flags' => $current?->claim_flags ?? [], 'missing_facts' => $current?->missing_facts ?? [], 'source_facts' => $current?->source_facts ?? [],
        ];
        if ($withVersions) {
            $out['versions'] = $a->versions()->limit(20)->get()->map(fn (ContentVersion $v) => [
                'version' => $v->version, 'generator' => $v->generator, 'body' => $v->body, 'created_at' => $v->created_at?->toIso8601String(),
                'flag_count' => count($v->claim_flags ?? []),
            ]);
        }

        return $out;
    }
}
