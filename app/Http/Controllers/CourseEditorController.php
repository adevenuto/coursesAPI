<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCourseRequest;
use App\Http\Requests\UpdateCourseRequest;
use App\Models\Course;
use App\Models\CourseRevision;
use App\Support\CourseWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;

/**
 * Editor-only course CRUD (paid users with the editor/admin role). Gated by
 * EnsureCourseEditor.
 *
 * The write itself lives in App\Support\CourseWriter, shared with the scorecard
 * scan apply so both produce identical layout_data, geo provenance and audit
 * entries. This controller is only the HTTP shell around it.
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
            'nearby' => (new Course)->nearbyCourses(),
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
            'nearby' => $course->nearbyCourses(),
        ]);
    }

    public function store(StoreCourseRequest $request, CourseWriter $writer): RedirectResponse
    {
        $course = $writer->write(new Course, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course created.']);

        return to_route('courses.edit', $course);
    }

    public function update(UpdateCourseRequest $request, Course $course, CourseWriter $writer): RedirectResponse
    {
        $writer->write($course, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course saved.']);

        return to_route('courses.edit', $course);
    }

    public function destroy(Request $request, Course $course, CourseWriter $writer): RedirectResponse
    {
        // Log the deletion before removing the row (nullOnDelete keeps the audit).
        $writer->record($course, 'deleted', [], $request->user());
        $course->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course deleted.']);

        return to_route('explorer');
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
}
