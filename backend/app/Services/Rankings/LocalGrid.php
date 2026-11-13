<?php

namespace App\Services\Rankings;

use App\Models\Business;
use App\Models\LocalGridScan;
use App\Models\UsageEvent;
use App\Services\AuditLogger;
use App\Services\Facts\BusinessFacts;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;

/** Where the business shows in Google Maps for a search, from a grid of points around a location. */
class LocalGrid
{
    public function __construct(private readonly MapsProvider $maps, private readonly BusinessFacts $facts, private readonly AuditLogger $audit) {}

    /** Points used this calendar month by the workspace. */
    public function pointsUsedThisMonth(Business $business): int
    {
        return (int) UsageEvent::query()->withoutGlobalScopes()->where('workspace_id', $business->workspace_id)
            ->where('action', 'local_grid_point')->where('created_at', '>=', now()->startOfMonth())->sum('units');
    }

    /** @return list<array{row: int, col: int, lat: float, lng: float}> row 0 is north, col 0 is west */
    public static function points(float $lat, float $lng, int $size, float $spacingKm): array
    {
        $half = intdiv($size, 2);
        $dLat = $spacingKm / 110.574;
        $dLng = $spacingKm / (111.320 * max(0.01, cos(deg2rad($lat))));
        $out = [];
        for ($r = 0; $r < $size; $r++) {
            for ($c = 0; $c < $size; $c++) {
                $out[] = ['row' => $r, 'col' => $c, 'lat' => round($lat + ($half - $r) * $dLat, 7), 'lng' => round($lng + ($c - $half) * $dLng, 7)];
            }
        }

        return $out;
    }

    public function run(LocalGridScan $scan): void
    {
        $business = Business::query()->findOrFail($scan->business_id);
        $scan->update(['status' => 'running']);
        $matcher = $this->matcher($business);
        $points = [];
        $cost = 0.0;
        $error = null;
        foreach (self::points($scan->center_lat, $scan->center_lng, $scan->size, $scan->spacing_km) as $p) {
            try {
                $result = $this->maps->places($scan->keyword, $p['lat'], $p['lng'], $business->default_language ?: 'en', $scan->depth);
            } catch (SerpException $e) {
                Log::warning('maps provider failed', ['scan_id' => $scan->id, 'error' => $e->getMessage()]);
                $error = $e->getMessage();
                break;
            }
            $mine = collect($result['places'])->first($matcher);
            $points[] = $p + [
                'position' => $mine['position'] ?? null,
                'top' => array_map(fn ($x) => $x + ['you' => $matcher($x)], array_slice($result['places'], 0, 3)),
            ];
            $cost += $result['cost'];
            UsageEvent::create([
                'workspace_id' => $business->workspace_id, 'provider' => $this->maps->name(), 'action' => 'local_grid_point',
                'units' => 1, 'estimated_cost' => $result['cost'], 'context' => ['business_id' => $business->id, 'scan_id' => $scan->id],
            ]);
        }

        $found = collect($points)->whereNotNull('position');
        $total = $scan->size * $scan->size;
        $scan->update([
            'points' => $points,
            'points_found' => $found->count(),
            'average_position' => $found->isNotEmpty() ? round($found->avg('position'), 2) : null,
            'cost' => round($cost, 5),
            'status' => count($points) === $total ? 'completed' : (count($points) > 0 ? 'partial' : 'failed'),
            'error' => $error ? 'The maps provider had a problem after '.count($points).' of '.$total.' points. Points not checked were not charged.' : null,
            'completed_at' => now(),
        ]);
        $this->audit->log('local_grid.completed', $scan, null, ['keyword' => $scan->keyword, 'points' => count($points), 'found' => $found->count()]);
    }

    /** A place is the business when its website is the business's, its phone is the business's, or its name matches exactly. */
    public function matcher(Business $business): callable
    {
        $facts = $this->facts->for($business);
        $own = OwnPresence::for($business);
        $country = $business->country ?: 'NG';
        $phones = array_filter(array_map(fn ($p) => Phone::normalize($p, $country), array_merge($facts['phones'], $facts['whatsapp'])));
        $names = array_map(fn ($n) => self::norm($n), array_filter(array_merge([$facts['name']], $facts['aliases'])));

        return function (array $place) use ($own, $phones, $names, $country) {
            if ($place['url'] && $own->owns($place['url'])) {
                return true;
            }
            if ($place['domain'] && $own->ownsDomain($place['domain'])) {
                return true;
            }
            if ($place['phone'] && in_array(Phone::normalize($place['phone'], $country), $phones, true)) {
                return true;
            }

            return in_array(self::norm($place['title']), $names, true);
        };
    }

    private static function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($s))));
    }
}
