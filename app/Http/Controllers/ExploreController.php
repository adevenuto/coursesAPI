<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public (IP-throttled) endpoints that return the courses for a geographic
 * area, plus map bounds. These power the explorer's results list and map;
 * they are intentionally separate from the authenticated/quota-counted
 * /api/v1 product API. Each Algolia geo record points its `url` here.
 */
class ExploreController extends Controller
{
    /** Cap markers returned to the map for a single area. */
    private const MAX_MARKERS = 500;

    private const MILES_PER_KM = 0.621371;

    public function city(Request $request, City $city): JsonResponse
    {
        $label = collect([$city->name, $city->state_name, $city->country_name])->filter()->implode(', ');
        $radiusMi = $this->radiusMiles($request);

        // Radius mode: everything within N miles of the city center (nearest-first).
        if ($radiusMi !== null && $city->latitude !== null && $city->longitude !== null) {
            $lat = (float) $city->latitude;
            $lng = (float) $city->longitude;

            return $this->build(
                [
                    'type' => 'city',
                    'id' => $city->id,
                    'name' => $city->name,
                    'label' => $label,
                    'radius_mi' => $radiusMi,
                    'center' => ['lat' => $lat, 'lng' => $lng],
                ],
                Course::near($lat, $lng, $radiusMi / self::MILES_PER_KM),
                radius: true,
            );
        }

        // Exact mode: courses assigned to this city.
        return $this->build(
            ['type' => 'city', 'id' => $city->id, 'name' => $city->name, 'label' => $label],
            Course::query()->where('city_id', $city->id),
        );
    }

    public function state(State $state): JsonResponse
    {
        return $this->build(
            [
                'type' => 'state',
                'id' => $state->id,
                'name' => $state->name,
                'label' => collect([$state->name, $state->country_name])->filter()->implode(', '),
            ],
            Course::query()->where('state_prov_id', $state->id),
        );
    }

    public function country(Country $country): JsonResponse
    {
        return $this->build(
            ['type' => 'country', 'id' => $country->id, 'name' => $country->name, 'label' => $country->name],
            Course::query()->where('country_id', $country->id),
        );
    }

    /**
     * Validate the optional `radius` query param (miles). Returns null when
     * absent/invalid so the caller falls back to exact mode.
     */
    private function radiusMiles(Request $request): ?float
    {
        $raw = $request->query('radius');

        if ($raw === null || ! is_numeric($raw) || (float) $raw <= 0) {
            return null;
        }

        $maxMi = floor(((int) config('api.max_radius_km', 100)) * self::MILES_PER_KM);

        return min((float) $raw, $maxMi);
    }

    /**
     * Build the shared { area, bounds, courses } payload. In radius mode the
     * query is already `scopeNear` (distance ordered, distance_km selected).
     *
     * @param  array<string, mixed>  $area
     */
    private function build(array $area, Builder $query, bool $radius = false): JsonResponse
    {
        $base = $query->whereNotNull('lat')->whereNotNull('lng');

        $total = (clone $base)->count();

        $rows = (clone $base)->with(['city:id,name', 'state:id,name'])->limit(self::MAX_MARKERS);

        // Radius keeps scopeNear's distance order + distance_km column; exact
        // mode sorts by name and can trim to just the columns it needs.
        $courses = $radius
            ? $rows->get()
            : $rows->orderBy('course_name')->get(['id', 'course_name', 'club_name', 'city_id', 'state_prov_id', 'lat', 'lng']);

        return response()->json([
            'area' => $area,
            'bounds' => $this->bounds($courses),
            'count' => $total,
            'returned' => $courses->count(),
            'capped' => $total > $courses->count(),
            'courses' => $courses->map(function (Course $c) use ($radius) {
                $out = [
                    'id' => $c->id,
                    'name' => $c->course_name,
                    'club' => $c->club_name,
                    'city' => $c->city?->name,
                    'state' => $c->state?->name,
                    'lat' => (float) $c->lat,
                    'lng' => (float) $c->lng,
                    'url' => '/courses/'.$c->id.'/'.$c->urlSlug(),
                ];

                if ($radius && $c->distance_km !== null) {
                    $out['distance_mi'] = round(((float) $c->distance_km) * self::MILES_PER_KM, 1);
                }

                return $out;
            })->all(),
        ]);
    }

    /**
     * Min/max lat/lng over the course set (for map zoom-to-bounds), or null.
     *
     * @param  Collection<int, Course>  $courses
     * @return array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}|null
     */
    private function bounds(Collection $courses): ?array
    {
        if ($courses->isEmpty()) {
            return null;
        }

        return [
            'min_lat' => (float) $courses->min('lat'),
            'max_lat' => (float) $courses->max('lat'),
            'min_lng' => (float) $courses->min('lng'),
            'max_lng' => (float) $courses->max('lng'),
        ];
    }
}
