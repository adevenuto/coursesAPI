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
            // Present only on near-me queries. Both units ship regardless of
            // what `units` asked for: `distance_mi` is the new primary, and
            // `distance_km` rides along unchanged so integrations written
            // against the kilometres-only API keep parsing. `distance_km` is
            // deprecated and goes away once those have moved over.
            $this->mergeWhen(isset($this->distance_km), fn () => [
                'distance_mi' => round(Distance::fromKm((float) $this->distance_km, Distance::MI), 2),
                'distance_km' => round((float) $this->distance_km, 2),
            ]),
        ];
    }
}
