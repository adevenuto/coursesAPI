<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\JsonResponse;

class GreenCenterController extends Controller
{
    /**
     * Per-hole green centers for a course (premium; gated by EnsurePremium).
     */
    public function show(Course $course): JsonResponse
    {
        $greens = $course->green_centers;

        if ($greens === null) {
            return response()->json([
                'message' => 'No green-center data available for this course.',
            ], 404);
        }

        return response()->json([
            'data' => [
                'course_id' => $course->id,
                'source' => $course->green_centers_source,
                'holes' => $greens,
            ],
        ]);
    }
}
