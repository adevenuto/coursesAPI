<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use App\Support\CourseLayoutWriter;
use App\Support\GeoResolver;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editor-only course CRUD (paid users with the editor/admin role). Gated by
 * EnsureCourseEditor. Writes the course scalar fields + layout_data (teeboxes
 * and per-hole green centers) via CourseLayoutWriter, preserving vendor keys.
 */
class CourseEditorController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('CourseEdit', [
            'mode' => 'create',
            'course' => null,
            'mapsKey' => config('services.google.places_key'),
        ]);
    }

    public function edit(Course $course): Response
    {
        return Inertia::render('CourseEdit', [
            'mode' => 'edit',
            'course' => $course->forEditor(),
            'mapsKey' => config('services.google.places_key'),
        ]);
    }

    public function store(StoreCourseRequest $request): RedirectResponse
    {
        $course = $this->apply(new Course, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course created.']);

        return to_route('courses.edit', $course);
    }

    public function update(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $this->apply($course, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course saved.']);

        return to_route('courses.edit', $course);
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course deleted.']);

        return to_route('explorer');
    }

    /**
     * Persist the validated payload: scalar fields + merged layout_data
     * (vendor keys preserved), provenance, and auto-derived geo IDs.
     *
     * @param  array<string, mixed>  $v
     */
    private function apply(Course $course, array $v): Course
    {
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

        $this->deriveGeo($course);

        $course->save(); // Scout syncs the course to Algolia automatically

        $this->reindexGeo($course);

        return $course;
    }

    /** Assign city/state/country from the coordinates (nearest dr5hn city). */
    private function deriveGeo(Course $course): void
    {
        if ($course->lat === null || $course->lng === null) {
            return;
        }

        $city = app(GeoResolver::class)->nearestCity((float) $course->lat, (float) $course->lng);

        if ($city !== null) {
            $course->city_id = $city->id;
            $course->state_prov_id = $city->state_id;
            $course->country_id = $city->country_id;
        }
    }

    /** Keep the explorer's geo indices in sync (a newly-populated city, etc.). */
    private function reindexGeo(Course $course): void
    {
        try {
            $course->loadMissing('city', 'state', 'country');
            $course->city?->searchable();
            $course->state?->searchable();
            $course->country?->searchable();
        } catch (\Throwable) {
            // Non-fatal: search reindex must never block a save.
        }
    }
}
