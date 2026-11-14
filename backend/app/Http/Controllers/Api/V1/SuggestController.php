<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Rule-based autocomplete: words this workspace already uses come first, then a fixed list.
 * No AI and no outside calls, so it is instant and works on every plan.
 */
class SuggestController extends Controller
{
    public function show(Request $request, string $field): JsonResponse
    {
        abort_unless(in_array($field, ['categories', 'cities', 'services'], true), 404);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'category' => ['nullable', 'string', 'max:120']]);
        $q = Str::lower(trim((string) ($data['q'] ?? '')));

        $own = match ($field) {
            'categories' => Business::query()->whereNotNull('primary_category')->distinct()->pluck('primary_category')->all(),
            'cities' => BusinessLocation::query()->whereNotNull('city')->distinct()->pluck('city')->all(),
            'services' => [],
        };
        $list = $field === 'services'
            ? $this->servicesFor((string) ($data['category'] ?? ''))
            : (array) config("suggestions.{$field}");

        return response()->json(['data' => self::rank(array_merge($own, $list), $q)]);
    }

    /** @return list<string> */
    private function servicesFor(string $category): array
    {
        $all = (array) config('suggestions.services');
        foreach ($all as $cat => $services) {
            if (Str::lower($cat) === Str::lower(trim($category))) {
                return $services;
            }
        }

        return [];
    }

    /** Prefix matches first, then matches inside a word; unique, case-insensitive, at most 8. */
    public static function rank(array $items, string $q, int $limit = 8): array
    {
        $seen = [];
        $starts = [];
        $contains = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            $key = Str::lower($item);
            if ($item === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($q === '' || str_starts_with($key, $q)) {
                $starts[] = $item;
            } elseif (str_contains($key, ' '.$q) || str_contains($key, '-'.$q) || str_contains($key, $q)) {
                $contains[] = $item;
            }
        }

        return array_slice(array_merge($starts, $contains), 0, $limit);
    }
}
