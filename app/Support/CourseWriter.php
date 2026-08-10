<?php

namespace App\Support;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\CourseRevision;
use App\Models\State;
use App\Models\User;

/**
 * The single write path for a course.
 *
 * Extracted from CourseEditorController so the manual editor and the scorecard
 * scan apply share one implementation: a scan-applied change should be
 * indistinguishable from a hand edit in the audit log, and there should be
 * exactly one place that decides how layout_data, geo provenance and revisions
 * are written.
 *
 * Callers pass the same attribute shape the editor validates. Every scalar key
 * is expected to be present — an omitted key clears the column, which is what
 * the editor means by an empty input. Callers changing only part of a course
 * (the scan apply) build a full payload from the current values first.
 */
class CourseWriter
{
    public function __construct(private readonly GeoResolver $geo) {}

    /**
     * Persist a course and record what changed.
     *
     * @param  array<string, mixed>  $attributes  validated editor payload
     */
    public function write(Course $course, array $attributes, ?User $editor): Course
    {
        $isNew = ! $course->exists;
        $before = CourseAuditor::snapshot($course);

        $course->fill([
            'course_name' => $attributes['course_name'],
            'club_name' => $attributes['club_name'] ?? null,
            'address' => $attributes['address'] ?? null,
            'postal_code' => $attributes['postal_code'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'website' => $attributes['website'] ?? null,
            'lat' => $attributes['lat'] ?? null,
            'lng' => $attributes['lng'] ?? null,
        ]);

        $course->layout_data = CourseLayoutWriter::merge(
            is_array($course->layout_data) ? $course->layout_data : null,
            $attributes['teeboxes'] ?? [],
            $attributes['green_centers'] ?? [],
            $attributes['hole_count'] ?? null,
        );

        // Provenance: a human-verified edit.
        $course->geo_source = 'manual';
        $course->geo_confidence = 1;
        $course->needs_review = false;
        $course->updated_by = $editor?->id;

        $previousGeo = [$course->city_id, $course->state_prov_id, $course->country_id];

        $this->deriveGeo($course, $attributes);

        $course->save(); // Scout syncs the course to Algolia automatically

        // The geo relations were loaded against the old ids; drop them so the
        // after-snapshot reads the location we just assigned.
        $course->unsetRelation('city')->unsetRelation('state')->unsetRelation('country');

        $this->reindexGeo($course, $previousGeo);

        $changes = CourseAuditor::diff($before, CourseAuditor::snapshot($course));
        if ($isNew || $changes) {
            $this->record($course, $isNew ? 'created' : 'updated', $changes, $editor);
        }

        return $course;
    }

    /**
     * Append an audit entry without touching the course. Used for deletes, where
     * the row is about to disappear.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    public function record(Course $course, string $action, array $changes, ?User $editor): CourseRevision
    {
        return CourseRevision::create([
            'course_id' => $course->id,
            'course_name' => $course->course_name,
            'user_id' => $editor?->id,
            'user_name' => $editor?->name,
            'action' => $action,
            'changes' => $changes ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Assign city/state/country.
     *
     * When the editor picked a place in the locator, Google's address components
     * come along with the payload and win: they're authoritative for country and
     * state, so the coordinate only chooses a city inside them. Without them we
     * fall back to the nearest dr5hn city — but only when the coordinates moved
     * or the ids are missing, so an unrelated edit can't revert a good match.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function deriveGeo(Course $course, array $attributes): void
    {
        if ($course->lat === null || $course->lng === null) {
            return;
        }

        $lat = (float) $course->lat;
        $lng = (float) $course->lng;

        $parts = [
            'country_code' => $attributes['place_country_code'] ?? null,
            'country_name' => $attributes['place_country_name'] ?? null,
            'state_code' => $attributes['place_state_code'] ?? null,
            'state_name' => $attributes['place_state_name'] ?? null,
            'city_candidates' => $attributes['place_city_candidates'] ?? [],
        ];

        if (array_filter($parts, fn ($part) => filled($part))) {
            $match = $this->geo->fromAddressComponents($parts, $lat, $lng);
        } elseif ($course->isDirty(['lat', 'lng']) || $course->city_id === null
            || $course->state_prov_id === null || $course->country_id === null) {
            $match = $this->geo->nearestCity($lat, $lng);
        } else {
            return; // Nothing about the location changed — leave the ids alone.
        }

        if ($match !== null) {
            $course->city_id = $match->id;
            $course->state_prov_id = $match->state_id;
            $course->country_id = $match->country_id;
        }
    }

    /**
     * Keep the explorer's geo indices in sync (a newly-populated city, etc.).
     * Rows the course just left are reindexed too, so their course counts and
     * shouldBeSearchable() state stay honest when a correction moves it.
     *
     * @param  array{0:?int,1:?int,2:?int}  $previousGeo
     */
    private function reindexGeo(Course $course, array $previousGeo = [null, null, null]): void
    {
        try {
            $course->loadMissing('city', 'state', 'country');
            $course->city?->searchable();
            $course->state?->searchable();
            $course->country?->searchable();

            [$cityId, $stateId, $countryId] = $previousGeo;
            if ($cityId !== null && $cityId !== $course->city_id) {
                City::find($cityId)?->searchable();
            }
            if ($stateId !== null && $stateId !== $course->state_prov_id) {
                State::find($stateId)?->searchable();
            }
            if ($countryId !== null && $countryId !== $course->country_id) {
                Country::find($countryId)?->searchable();
            }
        } catch (\Throwable) {
            // Non-fatal: search reindex must never block a save.
        }
    }
}
