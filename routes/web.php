<?php

use App\Http\Controllers\CourseShowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\ExplorerController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');
Route::get('docs', DocsController::class)->name('docs');

// Public course explorer (Algolia search showcase) + its data endpoints.
Route::get('explorer', ExplorerController::class)->name('explorer');
Route::get('courses/{course}/{slug?}', CourseShowController::class)->name('courses.show');

// Geo → courses data for the explorer map/results (public, IP-throttled).
Route::prefix('explore')->middleware('throttle:explore')->group(function () {
    Route::get('city/{city}', [ExploreController::class, 'city'])->name('explore.city');
    Route::get('state/{state}', [ExploreController::class, 'state'])->name('explore.state');
    Route::get('country/{country}', [ExploreController::class, 'country'])->name('explore.country');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
