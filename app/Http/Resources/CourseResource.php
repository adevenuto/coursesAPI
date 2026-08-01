<?php

namespace App\Http\Resources;

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
            // Present only on near-me queries.
            $this->mergeWhen(isset($this->distance_km), fn () => [
                'distance_km' => round((float) $this->distance_km, 2),
            ]),
        ];
    }
}
