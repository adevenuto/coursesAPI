<?php

use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\GreenCenterController;
use App\Http\Middleware\EnsurePremium;
use App\Http\Middleware\TrackApiRequest;
use App\Http\Middleware\TrackApiUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The shared API stack, defined once so the two route groups can't drift apart.
 *
 * A variable rather than a const: the routes file is re-included on every test
 * bootstrap, and a file-scope const would redefine and fatal.
 *
 * Order is load-bearing:
 *   auth:sanctum      resolve the user first, so a 429 is still attributable
 *   TrackApiRequest   detail log — above the throttler, so it records rejections
 *   throttle:api      per-plan daily quota + burst cap
 *   TrackApiUsage     billing rollup — unreachable on a 429, exactly as before
 *
 * ...but this listing alone does NOT establish that order. Laravel re-sorts route
 * middleware by its priority list, which hoists ThrottleRequests above anything
 * unlisted — so TrackApiRequest is pinned ahead of it in bootstrap/app.php. Remove
 * that pin and throttled requests silently stop being recorded, with no error.
 * MiddlewareOrderTest guards it.
 *
 * EnsurePremium stays route-level, inside all four, so premium 403s continue to
 * count toward usage as they always have.
 */
$apiStack = [
    'auth:sanctum',
    TrackApiRequest::class,
    'throttle:api',
    TrackApiUsage::class,
];

// Previously the one authenticated endpoint with neither throttling nor
// tracking. It now shares the stack: visible in analytics, and consuming quota
// like every other billed call.
Route::get('/user', fn (Request $request) => $request->user())
    ->middleware($apiStack);

Route::prefix('v1')
    ->middleware($apiStack)
    ->group(function () {
        // Courses
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('courses/{course}', [CourseController::class, 'show']);

        // Green centers (premium)
        Route::get('courses/{course}/green-centers', [GreenCenterController::class, 'show'])
            ->middleware(EnsurePremium::class);

        // Geo lookups (free, for building filters)
        Route::get('countries', [GeoController::class, 'countries']);
        Route::get('states', [GeoController::class, 'states']);
        Route::get('cities', [GeoController::class, 'cities']);
    });
