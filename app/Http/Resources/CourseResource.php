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
            // Present only on near-me queries. Both units ship on every result
            // whatever `units` asked for — that parameter chooses how `radius`
            // is read, not what comes back. Neither key is going away: the
            // audience spans both conventions, and dropping one would buy
            // nothing but a future breaking change.
            $this->mergeWhen(isset($this->distance_km), fn () => [
                'distance_mi' => round(Distance::fromKm((float) $this->distance_km, Distance::MI), 2),
                'distance_km' => round((float) $this->distance_km, 2),
            ]),
        ];
    }
}
