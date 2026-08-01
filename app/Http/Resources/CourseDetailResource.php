<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Course
 */
class CourseDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $premium = (bool) $request->user()?->hasPremium();
        $hasGreens = $this->green_centers !== null;

        return [
            'id' => $this->id,
            'name' => $this->course_name,
            'club' => $this->club_name,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'phone' => $this->phone,
            'website' => $this->website,
            'location' => [
                'city' => $this->city?->name,
                'state' => $this->state?->name,
                'country' => $this->country ? [
                    'name' => $this->country->name,
                    'iso2' => $this->country->iso2,
                ] : null,
            ],
            'coordinates' => [
                'latitude' => $this->lat,
                'longitude' => $this->lng,
            ],
            'scorecard' => $this->scorecard,
            'green_centers_available' => $hasGreens,
            // Premium payload — included only for paid plans that have data.
            'green_centers' => $this->when($premium && $hasGreens, fn () => [
                'source' => $this->green_centers_source,
                'holes' => $this->green_centers,
            ]),
        ];
    }
}
