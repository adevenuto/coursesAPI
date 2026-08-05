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

            'teeboxes' => ['present', 'array', 'max:12'],
            'teeboxes.*.name' => ['required', 'string', 'max:60'],
            'teeboxes.*.color' => ['nullable', $hex],
            'teeboxes.*.secondaryColor' => ['nullable', $hex],
            'teeboxes.*.slope' => ['nullable', 'integer', 'between:55,155'],
            'teeboxes.*.courseRating' => ['nullable', 'numeric', 'between:55,80'],
            'teeboxes.*.totalYardage' => ['nullable', 'integer', 'between:0,12000'],
            'teeboxes.*.holes' => ['present', 'array', 'max:36'],
            'teeboxes.*.holes.*.hole' => ['required', 'integer', 'between:1,36'],
            'teeboxes.*.holes.*.par' => ['nullable', 'integer', 'between:3,6'],
            'teeboxes.*.holes.*.length' => ['nullable', 'integer', 'between:30,900'],
            'teeboxes.*.holes.*.handicap' => ['nullable', 'integer', 'between:1,36'],

            'green_centers' => ['present', 'array', 'max:36'],
            'green_centers.*.hole' => ['required', 'integer', 'between:1,36'],
            'green_centers.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'green_centers.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
