<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public course details page (a link target for the explorer). Bound by ID;
 * the slug segment is cosmetic and canonicalized. Intentionally light for now.
 */
class CourseShowController extends Controller
{
    public function __invoke(Course $course, ?string $slug = null): Response|RedirectResponse
    {
        // Keep the URL canonical (id resolves the course; slug is decoration).
        $canonical = $course->urlSlug();
        if ($slug !== $canonical) {
            return redirect()->route('courses.show', ['course' => $course->id, 'slug' => $canonical]);
        }

        $course->load(['city:id,name', 'state:id,name', 'country:id,name,iso2']);

        return Inertia::render('CourseShow', [
            'canEdit' => (bool) request()->user()?->canEditCourses(),
            'course' => [
                'id' => $course->id,
                'name' => $course->course_name,
                'club' => $course->club_name,
                'address' => $course->address,
                'postal_code' => $course->postal_code,
                'phone' => $course->phone,
                'website' => $course->website,
                'location' => [
                    'city' => $course->city?->name,
                    'state' => $course->state?->name,
                    'country' => $course->country
                        ? ['name' => $course->country->name, 'iso2' => $course->country->iso2]
                        : null,
                ],
                'coordinates' => ['latitude' => $course->lat, 'longitude' => $course->lng],
                'scorecard' => $course->scorecard,
                'green_centers_available' => $course->hasGreenCenters(),
            ],
        ]);
    }
}
