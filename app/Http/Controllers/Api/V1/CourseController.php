<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCoursesRequest;
use App\Http\Resources\CourseCollection;
use App\Http\Resources\CourseDetailResource;
use App\Models\Course;

class CourseController extends Controller
{
    /**
     * List / search / filter / near-me. Always paginated.
     */
    public function index(IndexCoursesRequest $request): CourseCollection
    {
        $perPage = (int) $request->integer('per_page', (int) config('api.pagination.default_per_page', 25));

        $query = Course::query()->with(['city', 'state', 'country']);

        // Near-me overrides ordering with distance.
        if ($request->filled('lat') && $request->filled('lng')) {
            $radius = (float) $request->input('radius', config('api.max_radius_km', 100));
            $query->near((float) $request->input('lat'), (float) $request->input('lng'), $radius);
        }

        $query->search($request->input('q'));

        if ($request->filled('country')) {
            $query->inCountry((string) $request->input('country'));
        }
        if ($request->filled('state_prov_id')) {
            $query->where('state_prov_id', $request->integer('state_prov_id'));
        }
        if ($request->filled('city_id')) {
            $query->where('city_id', $request->integer('city_id'));
        }

        // Stable default ordering for non-geo queries.
        if (! ($request->filled('lat') && $request->filled('lng'))) {
            $query->orderBy('course_name')->orderBy('id');
        }

        $courses = $query->paginate($perPage)->withQueryString();

        return new CourseCollection($courses);
    }

    public function show(Course $course): CourseDetailResource
    {
        $course->load(['city', 'state', 'country']);

        return new CourseDetailResource($course);
    }
}
