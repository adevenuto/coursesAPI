<?php

namespace App\Support;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\CourseSuggestion;
use App\Models\State;

/**
 * Reconciles the ids a suggestion arrives with against what actually exists.
 *
 * Two jobs, and both exist because the client cannot be trusted with either:
 *
 * 1. A correction names a course, and that course — not the form — is the
 *    authority on its own name and location. The modal renders those fields
 *    readonly, but readonly is a courtesy; anyone can POST anything.
 * 2. An id with no row behind it is dropped rather than rejected. The Algolia
 *    index lags the database, so a deleted course is still searchable and would
 *    otherwise hard-fail a submission the visitor has no way to fix. A dangling
 *    id would also be a foreign-key 500 rather than a validation error.
 *
 * Free text is never overwritten in missing mode: "Dornock" is the record of
 * what someone typed, and spotting that typo is the manual research step.
 */
class SuggestionGeo
{
    /**
     * @param  array<string, mixed>  $data  validated input
     * @return array<string, mixed> the keys to overwrite on the row
     */
    public static function resolve(array $data): array
    {
        $type = $data['type'] ?? null;

        return $type === CourseSuggestion::TYPE_CORRECTION
            ? self::fromCourse($data)
            : self::fromGeoIds($data);
    }

    /**
     * A correction: the course row wins on every identifying field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fromCourse(array $data): array
    {
        $id = self::intOrNull($data['course_id'] ?? null);

        $course = $id === null ? null : Course::with(['city:id,name', 'state:id,name', 'country:id,name'])->find($id);

        if ($course === null) {
            // Unresolvable: keep whatever they sent so the report is still
            // actionable by hand, and don't store a course_id that points nowhere.
            return ['course_id' => null];
        }

        return [
            'course_id' => $course->id,
            'course_name' => $course->course_name,
            'club_name' => $course->club_name,
            'country' => $course->country?->name,
            'state' => $course->state?->name,
            'city' => $course->city?->name,
            'country_id' => $course->country_id,
            'state_id' => $course->state_prov_id,
            'city_id' => $course->city_id,
        ];
    }

    /**
     * A missing course: walk down from the most specific id supplied and read
     * the whole trio off whichever row resolves.
     *
     * Same principle as GeoResolver::fromAddressComponents() — taking all three
     * ids from one row is what makes them internally consistent, and it means no
     * client can post a city in the wrong state however it tries.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fromGeoIds(array $data): array
    {
        $none = ['course_id' => null, 'country_id' => null, 'state_id' => null, 'city_id' => null];

        if ($cityId = self::intOrNull($data['city_id'] ?? null)) {
            $city = City::find($cityId, ['id', 'state_id', 'country_id']);

            if ($city !== null) {
                return [...$none, 'city_id' => $city->id, 'state_id' => $city->state_id, 'country_id' => $city->country_id];
            }
        }

        if ($stateId = self::intOrNull($data['state_id'] ?? null)) {
            $state = State::find($stateId, ['id', 'country_id']);

            if ($state !== null) {
                return [...$none, 'state_id' => $state->id, 'country_id' => $state->country_id];
            }
        }

        if ($countryId = self::intOrNull($data['country_id'] ?? null)) {
            if (Country::whereKey($countryId)->exists()) {
                return [...$none, 'country_id' => $countryId];
            }
        }

        return $none;
    }

    private static function intOrNull(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false || $int < 1 ? null : $int;
    }
}
