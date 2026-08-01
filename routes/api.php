<?php

use App\Http\Controllers\Api\V1\CourseController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\GreenCenterController;
use App\Http\Middleware\EnsurePremium;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
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
