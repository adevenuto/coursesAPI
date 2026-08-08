<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;
use Laravel\Head\Facades\Schema;

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

        $this->head($course);

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

    /**
     * Page metadata for this course. Course rows vary a lot in completeness, so
     * the description is assembled from whichever parts are actually present.
     */
    protected function head(Course $course): void
    {
        $name = trim((string) $course->course_name) ?: trim((string) $course->club_name) ?: 'Golf course';
        $url = route('courses.show', ['course' => $course->id, 'slug' => $course->urlSlug()]);

        Head::title($name)
            ->description($this->description($course, $name))
            ->canonical($url)
            ->schema(Schema::golfCourse()
                ->name($name)
                ->url($url)
                ->telephone($course->phone)
                ->sameAs($course->website)
                ->address(
                    street: $course->address,
                    locality: $course->city?->name,
                    region: $course->state?->name,
                    postalCode: $course->postal_code,
                    country: $course->country?->iso2,
                )
                ->geo($course->lat, $course->lng))
            // Built with item() rather than items([...]): the course name would
            // be an array key there, and PHP casts integer-like keys to int —
            // which blows up on the 42 courses actually named "2018", "2004"...
            ->schema(Schema::breadcrumbs()
                ->item('Home', route('home'))
                ->item('Course Explorer', route('explorer'))
                ->item($name, $url));
    }

    /**
     * Build a meta description from the course's location and available data.
     */
    protected function description(Course $course, string $name): string
    {
        $place = collect([$course->city?->name, $course->state?->name, $course->country?->name])
            ->filter()
            ->implode(', ');

        $sentence = $place !== ''
            ? "{$name} in {$place}."
            : "{$name}.";

        $has = ['Course details'];

        if ($course->scorecard !== null) {
            $has[] = 'scorecard with par and yardage';
        }

        if ($course->hasGreenCenters()) {
            $has[] = 'per-hole green-center GPS coordinates';
        }

        return $sentence.' '.implode(', ', $has).' — free via the GCA golf course API.';
    }
}
