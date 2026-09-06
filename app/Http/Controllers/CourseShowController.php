<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Support\TeeColor;
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

        $mapsKey = (string) config('services.google.places_key');

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
                'scorecard' => self::scorecardWithTeeColours($course),
                'green_centers_available' => $course->hasGreenCenters(),
            ],
            // The extent of the course, for framing the map.
            //
            // Deliberately the bounding box and not the green centers it is
            // derived from: this page is public, anything handed to it is
            // readable in the Inertia payload, and per-hole green GPS is the
            // one thing EnsurePremium gates on the API. Four numbers frame the
            // map just as well and give away an outline a satellite view
            // already shows.
            'bounds' => self::greenBounds($course),
            // Other routings of the same club first, then genuine neighbours.
            // Public-safe: names, hole counts and distances, no green GPS.
            'nearby' => $course->nearbyCourses(),
            // Browser key, referer-restricted (see config/services.php). The map
            // is loaded lazily client-side, so an unset key degrades to the
            // printed coordinates rather than a broken frame.
            'maps' => [
                'key' => $mapsKey,
                'configured' => filled($mapsKey),
            ],
            // Tee colours are resolved from the tee name rather than stored on
            // the scorecard, the same way the editor and the scan pipeline do
            // it. Shipping the vocabulary keeps one authority in PHP.
            'teeColors' => [
                'palette' => TeeColor::palette(),
                'vocabulary' => TeeColor::vocabulary(),
                'ignore' => TeeColor::ignored(),
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

    /**
     * The public scorecard plus each tee's stored colour.
     *
     * Merged here rather than added to Course::scorecard, because that accessor
     * is what CourseDetailResource returns: widening it would change the API
     * payload and stale every captured example in the docs. The page needs the
     * colour only to draw a swatch, so it stays a page concern.
     *
     * Zipped by index — getScorecardAttribute() walks layout_data['teeboxes'] in
     * order, so position i means the same tee in both lists.
     *
     * @return array<string, mixed>|null
     */
    private static function scorecardWithTeeColours(Course $course): ?array
    {
        $scorecard = $course->scorecard;

        if ($scorecard === null) {
            return null;
        }

        $stored = array_values(is_array($course->layout_data) ? ($course->layout_data['teeboxes'] ?? []) : []);

        $scorecard['teeboxes'] = array_map(
            fn (array $tee, int $i) => $tee + [
                'color' => $stored[$i]['color'] ?? null,
                'secondary_color' => $stored[$i]['secondaryColor'] ?? null,
            ],
            $scorecard['teeboxes'],
            array_keys($scorecard['teeboxes']),
        );

        return $scorecard;
    }

    /**
     * Bounding box of the course's mapped greens, or null when it has none.
     *
     * @return array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}|null
     */
    private static function greenBounds(Course $course): ?array
    {
        $greens = $course->green_centers;

        if (! $greens) {
            return null;
        }

        $lats = array_column($greens, 'lat');
        $lngs = array_column($greens, 'lng');

        return [
            'min_lat' => min($lats),
            'max_lat' => max($lats),
            'min_lng' => min($lngs),
            'max_lng' => max($lngs),
        ];
    }
}
