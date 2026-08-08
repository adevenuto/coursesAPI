<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use App\Models\CourseRevision;
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

        $this->deriveGeo($course);

        $course->save(); // Scout syncs the course to Algolia automatically

        $this->reindexGeo($course);

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
