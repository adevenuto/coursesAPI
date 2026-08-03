<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

    public function city(City $city): JsonResponse
    {
        return $this->respond(
            'city',
            $city->id,
            $city->name,
            collect([$city->name, $city->state_name, $city->country_name])->filter()->implode(', '),
            Course::query()->where('city_id', $city->id),
        );
    }

    public function state(State $state): JsonResponse
    {
        return $this->respond(
            'state',
            $state->id,
            $state->name,
            collect([$state->name, $state->country_name])->filter()->implode(', '),
            Course::query()->where('state_prov_id', $state->id),
        );
    }

    public function country(Country $country): JsonResponse
    {
        return $this->respond(
            'country',
            $country->id,
            $country->name,
            $country->name,
            Course::query()->where('country_id', $country->id),
        );
    }

    /**
     * Build the { area, bounds, courses } payload for a geo's courses.
     */
    private function respond(string $type, int $id, string $name, string $label, Builder $query): JsonResponse
    {
        $base = $query->whereNotNull('lat')->whereNotNull('lng');

        $total = (clone $base)->count();

        $courses = (clone $base)
            ->with(['city:id,name', 'state:id,name'])
            ->orderBy('course_name')
            ->limit(self::MAX_MARKERS)
            ->get(['id', 'course_name', 'club_name', 'city_id', 'state_prov_id', 'lat', 'lng']);

        return response()->json([
            'area' => ['type' => $type, 'id' => $id, 'name' => $name, 'label' => $label],
            'bounds' => $this->bounds($courses),
            'count' => $total,
            'returned' => $courses->count(),
            'capped' => $total > $courses->count(),
            'courses' => $courses->map(fn (Course $c) => [
                'id' => $c->id,
                'name' => $c->course_name,
                'club' => $c->club_name,
                'city' => $c->city?->name,
                'state' => $c->state?->name,
                'lat' => (float) $c->lat,
                'lng' => (float) $c->lng,
                'url' => '/courses/'.$c->id.'/'.$c->urlSlug(),
            ])->all(),
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
