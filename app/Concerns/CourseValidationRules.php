<?php

namespace App\Concerns;

use App\Models\Course;
use App\Support\CourseRating;
use Closure;
use Illuminate\Support\Str;

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
            'teeboxes.*.slope' => ['nullable', 'integer', $this->slopeRule()],
            'teeboxes.*.slopeWomen' => ['nullable', 'integer', $this->slopeRule()],
            'teeboxes.*.courseRating' => ['nullable', 'numeric', $this->ratingRule()],
            'teeboxes.*.courseRatingWomen' => ['nullable', 'numeric', $this->ratingRule()],
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

    /**
     * Bound a course rating against the tee it belongs to rather than a flat
     * 55–80, which silently assumes eighteen holes and rejects every correctly
     * read nine-hole card. See App\Support\CourseRating.
     */
    protected function ratingRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $tee = data_get($this->all(), Str::beforeLast($attribute, '.'));
            $holes = is_array($tee) && is_array($tee['holes'] ?? null) ? $tee['holes'] : [];

            $min = CourseRating::min(CourseRating::playedHoles($holes));

            if ((float) $value >= $min && (float) $value <= CourseRating::MAX) {
                return;
            }

            if ($this->alreadyStored($attribute, $value)) {
                return;
            }

            $fail(sprintf('The :attribute field must be between %s and %s.', $min, CourseRating::MAX));
        };
    }

    /**
     * The same treatment for slope, which was a flat `between:55,155`.
     */
    protected function slopeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ((float) $value >= 55 && (float) $value <= 155) {
                return;
            }

            if ($this->alreadyStored($attribute, $value)) {
                return;
            }

            $fail('The :attribute field must be between 55 and 155.');
        };
    }

    /**
     * Is this exact value already on the course for this field?
     *
     * A value can be out of range and still correct — a par-3 eighteen rating
     * 50.4, or a figure an editor approved off an official card during a scan.
     * Once stored, re-validating it on every save would make the course
     * unsaveable until someone cleared a number that was never wrong. 103
     * courses are in that state today.
     *
     * So the bound applies to what an editor is *changing*, not to what is
     * already there. Matching on the value rather than on the tee's position is
     * deliberate: tees can be reordered, renamed and deleted between loads, so
     * an index is not a stable identity — but a value that is already in this
     * course's layout_data for this field cannot be smuggled in by an edit.
     *
     * A course being created has nothing stored, so it stays strict.
     */
    private function alreadyStored(string $attribute, mixed $value): bool
    {
        $course = $this->route('course');

        if (! $course instanceof Course) {
            return false;
        }

        $field = Str::afterLast($attribute, '.');
        $data = is_array($course->layout_data) ? $course->layout_data : [];

        foreach ($data['teeboxes'] ?? [] as $tee) {
            $stored = $tee[$field] ?? null;

            // Gendered values are stored as a scalar or a [men, women] pair.
            foreach (is_array($stored) ? $stored : [$stored] as $candidate) {
                if ($candidate !== null && $candidate !== '' && (float) $candidate === (float) $value) {
                    return true;
                }
            }
        }

        return false;
    }
}
