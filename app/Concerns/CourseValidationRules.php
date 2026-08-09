<?php

namespace App\Concerns;

trait CourseValidationRules
{
    /**
     * Rigorous rules for the course editor (store + update share these).
     * Coordinates, scorecard values, hex colors, and hole numbers are all
     * range-checked to protect data integrity.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function courseRules(): array
    {
        $hex = 'regex:/^#[0-9A-Fa-f]{6}$/';

        return [
            'course_name' => ['required', 'string', 'max:255'],
            'club_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'string', 'max:255'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'hole_count' => ['nullable', 'integer', 'between:1,36'],

            // Google Places address components for the place the editor just
            // picked, used to reconcile city_id/state_prov_id/country_id on save.
            // Absent on a plain save; never stored verbatim.
            'place_country_code' => ['nullable', 'string', 'max:120'],
            'place_country_name' => ['nullable', 'string', 'max:120'],
            'place_state_code' => ['nullable', 'string', 'max:120'],
            'place_state_name' => ['nullable', 'string', 'max:120'],
            'place_city_candidates' => ['nullable', 'array', 'max:6'],
            'place_city_candidates.*' => ['string', 'max:120'],

            'teeboxes' => ['present', 'array', 'max:12'],
            'teeboxes.*.name' => ['required', 'string', 'max:60'],
            'teeboxes.*.color' => ['nullable', $hex],
            'teeboxes.*.secondaryColor' => ['nullable', $hex],
            'teeboxes.*.slope' => ['nullable', 'integer', 'between:55,155'],
            'teeboxes.*.slopeWomen' => ['nullable', 'integer', 'between:55,155'],
            'teeboxes.*.courseRating' => ['nullable', 'numeric', 'between:55,80'],
            'teeboxes.*.courseRatingWomen' => ['nullable', 'numeric', 'between:55,80'],
            // Derived from the per-hole yards (up to 36 holes × 900), so the cap
            // is generous enough that a valid computed sum is never rejected.
            'teeboxes.*.totalYardage' => ['nullable', 'integer', 'between:0,40000'],
            'teeboxes.*.holes' => ['present', 'array', 'max:36'],
            'teeboxes.*.holes.*.hole' => ['required', 'integer', 'between:1,36'],
            'teeboxes.*.holes.*.par' => ['nullable', 'integer', 'between:3,6'],
            'teeboxes.*.holes.*.length' => ['nullable', 'integer', 'between:30,900'],
            'teeboxes.*.holes.*.handicap' => ['nullable', 'integer', 'between:1,36'],
            'teeboxes.*.holes.*.handicapWomen' => ['nullable', 'integer', 'between:1,36'],

            'green_centers' => ['present', 'array', 'max:36'],
            'green_centers.*.hole' => ['required', 'integer', 'between:1,36'],
            'green_centers.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'green_centers.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
