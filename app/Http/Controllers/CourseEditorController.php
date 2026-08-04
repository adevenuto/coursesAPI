<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Editor-only course CRUD (paid users with the editor/admin role). Gated by
 * EnsureCourseEditor. Writes the course scalar fields + layout_data (teeboxes
 * and per-hole green centers).
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

    // Checkpoint 2 fills these in (validation + CourseLayoutWriter + geo derive).
    public function store(): RedirectResponse
    {
        abort(501);
    }

    public function update(Course $course): RedirectResponse
    {
        abort(501);
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Course deleted.']);

        return to_route('explorer');
    }
}
