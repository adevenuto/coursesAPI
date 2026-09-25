<?php

namespace App\Http\Resources;

use App\Support\Distance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Course
 */
class CourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->course_name,
            'club' => $this->club_name,
            'city' => $this->city?->name,
            'state' => $this->state?->name,
            'country' => $this->country?->iso2,
            'latitude' => $this->lat,
            'longitude' => $this->lng,
            // Present only on near-me queries, keyed by the unit the caller
            // asked for: `distance_mi` by default, `distance_km` on units=km.
            $this->mergeWhen(isset($this->distance_km), function () use ($request) {
                $unit = Distance::unit($request->query('units'));

                return ['distance_'.$unit => round(Distance::fromKm((float) $this->distance_km, $unit), 2)];
            }),
        ];
    }
}
