<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\CourseRevision;
use App\Models\State;
use App\Models\User;
use App\Support\CourseAuditor;
use App\Support\CourseLayoutWriter;
use App\Support\GeoResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;

/**
 * Editor-only course CRUD (paid users with the editor/admin role). Gated by
 * EnsureCourseEditor. Writes the course scalar fields + layout_data (teeboxes
 * and per-hole green centers) via CourseLayoutWriter (vendor keys preserved),
 * and records a course_revisions audit entry for every change.
 */
class CourseEditorController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('CourseEdit', [
            'mode' => 'create',
            'course' => null,
            'mapsKey' => config('services.google.places_key'),
            'history' => [],
            'siblings' => [],
        ]);
    }

    public function edit(Course $course): Response
    {
        $course->load('updatedBy:id,name');

        Head::title(trim((string) $course->course_name) ?: 'Edit course');

        return Inertia::render('CourseEdit', [
            'mode' => 'edit',
            'course' => $course->forEditor(),
            'mapsKey' => config('services.google.places_key'),
            'history' => $this->history($course),
            'siblings' => $course->siblingsOnProperty(),
        ]);
    }

    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $course = $this->apply(new Course, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course created.']);

        return to_route('courses.edit', $course);
    }

    public function update(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->apply($course, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course saved.']);

        return to_route('courses.edit', $course);
    }

    public function destroy(Request $request, Course $course): RedirectResponse
    {
        // Log the deletion before removing the row (nullOnDelete keeps the audit).
        $this->record($course, 'deleted', [], $request->user());
        $course->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course deleted.']);

        return to_route('explorer');
    }

    /**
     * Persist the validated payload + record an audit entry.
     *
     * @param  array<string, mixed>  $v
     */
    private function apply(Course $course, array $v, User $editor): Course
    {
        $isNew = ! $course->exists;
        $before = CourseAuditor::snapshot($course);

        $course->fill([
            'course_name' => $v['course_name'],
            'club_name' => $v['club_name'] ?? null,
            'address' => $v['address'] ?? null,
            'postal_code' => $v['postal_code'] ?? null,
            'phone' => $v['phone'] ?? null,
            'website' => $v['website'] ?? null,
            'lat' => $v['lat'],
            'lng' => $v['lng'],
        ]);

        $course->layout_data = CourseLayoutWriter::merge(
            is_array($course->layout_data) ? $course->layout_data : null,
            $v['teeboxes'] ?? [],
            $v['green_centers'] ?? [],
            $v['hole_count'] ?? null,
        );

        // Provenance: a human-verified edit.
        $course->geo_source = 'manual';
        $course->geo_confidence = 1;
        $course->needs_review = false;
        $course->updated_by = $editor->id;

        $previousGeo = [$course->city_id, $course->state_prov_id, $course->country_id];

        $this->deriveGeo($course, $v);

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
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function record(Course $course, string $action, array $changes, ?User $editor): void
    {
        CourseRevision::create([
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
     * @return array<int, array<string, mixed>>
     */
    private function history(Course $course): array
    {
        return $course->revisions()->limit(30)->get()->map(fn (CourseRevision $r) => [
            'user' => $r->user_name ?? 'system',
            'action' => $r->action,
            'changes' => $r->changes ?? [],
            'at' => $r->created_at?->diffForHumans(),
        ])->all();
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
     * @param  array<string, mixed>  $v
     */
    private function deriveGeo(Course $course, array $v): void
    {
        if ($course->lat === null || $course->lng === null) {
            return;
        }

        $lat = (float) $course->lat;
        $lng = (float) $course->lng;
        $resolver = app(GeoResolver::class);

        $parts = [
            'country_code' => $v['place_country_code'] ?? null,
            'country_name' => $v['place_country_name'] ?? null,
            'state_code' => $v['place_state_code'] ?? null,
            'state_name' => $v['place_state_name'] ?? null,
            'city_candidates' => $v['place_city_candidates'] ?? [],
        ];

        if (array_filter($parts, fn ($part) => filled($part))) {
            $match = $resolver->fromAddressComponents($parts, $lat, $lng);
        } elseif ($course->isDirty(['lat', 'lng']) || $course->city_id === null
            || $course->state_prov_id === null || $course->country_id === null) {
            $match = $resolver->nearestCity($lat, $lng);
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
