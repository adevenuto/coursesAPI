<?php

use App\Http\Controllers\CourseEditorController;
use App\Http\Controllers\CourseShowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\ExplorerController;
use App\Http\Controllers\ScorecardScanController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\WebManifestController;
use App\Http\Controllers\WelcomeController;
use App\Http\Middleware\EnsureCourseEditor;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)
    ->name('home')
    ->withHead(
        // `exact` opts out of the inherited " — GCA" suffix. The brand is left
        // out entirely because Google appends the site name itself, so this
        // renders as "The Golf Courses API - GCA" in results.
        title: ['value' => 'The Golf Courses API', 'exact' => true],
        description: 'Thousands of golf courses worldwide — locations, scorecards and per-hole green-center GPS — behind one clean, fast REST API. Get a free key.',
    );

Route::get('docs', DocsController::class)
    ->name('docs')
    ->withHead(
        title: 'API Documentation',
        description: 'Golf Courses API (GCA) documentation: authentication, rate limits, pagination, and endpoint reference with live examples.',
    );

// Public course explorer (Algolia search showcase) + its data endpoints.
Route::get('explorer', ExplorerController::class)
    ->name('explorer')
    ->withHead(
        title: 'Course Explorer',
        description: 'Search 22,000+ golf courses by name, city, state, or country.',
    );

// Editor-only course CRUD (paid editor/admin). Registered BEFORE the public
// show route so `courses/create` and `courses/{id}/edit` aren't captured by it.
Route::withHead(robots: 'noindex, nofollow')->middleware(['auth', 'verified', EnsureCourseEditor::class])->group(function () {
    Route::get('courses/create', [CourseEditorController::class, 'create'])
        ->name('courses.create')
        ->withHead(title: 'New course');
    Route::post('courses', [CourseEditorController::class, 'store'])->name('courses.store');
    // Title is set at runtime from the course being edited.
    Route::get('courses/{course}/edit', [CourseEditorController::class, 'edit'])->name('courses.edit');
    Route::put('courses/{course}', [CourseEditorController::class, 'update'])->name('courses.update');
    Route::delete('courses/{course}', [CourseEditorController::class, 'destroy'])->name('courses.destroy');

    // Scorecard scanning: upload a card, parse it, review the diff, apply what
    // you accept. Staged on scorecard_scans — nothing reaches a course until
    // the apply step.
    Route::get('scorecard-scans/create', [ScorecardScanController::class, 'create'])
        ->name('scorecard-scans.create')
        ->withHead(title: 'Scan a scorecard');
    Route::post('scorecard-scans', [ScorecardScanController::class, 'store'])->name('scorecard-scans.store');
    Route::get('scorecard-scans/{scan}', [ScorecardScanController::class, 'show'])->name('scorecard-scans.show');
    Route::post('scorecard-scans/{scan}/parse', [ScorecardScanController::class, 'parse'])->name('scorecard-scans.parse');
    // Images live on the private disk, so they're streamed rather than served.
    Route::get('scorecard-scans/{scan}/images/{index}', [ScorecardScanController::class, 'image'])
        ->whereNumber('index')
        ->name('scorecard-scans.image');
    Route::post('scorecard-scans/{scan}/apply', [ScorecardScanController::class, 'apply'])->name('scorecard-scans.apply');
    Route::delete('scorecard-scans/{scan}', [ScorecardScanController::class, 'destroy'])->name('scorecard-scans.destroy');
});

Route::get('courses/{course}/{slug?}', CourseShowController::class)
    ->whereNumber('course')
    ->name('courses.show');

// Crawler-facing endpoints (plain text / XML, not Inertia pages). robots.txt is
// a route so its Sitemap line tracks APP_URL; there is no public/robots.txt.
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');

// Web app manifest — a route so the icon URLs stay versioned and the theme
// colour comes from the same constant as the meta tag. No static file exists.
Route::get('site.webmanifest', WebManifestController::class)->name('manifest');
Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('sitemap/pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('sitemap/courses-{page}.xml', [SitemapController::class, 'courses'])
    ->whereNumber('page')
    ->name('sitemap.courses');

// Geo → courses data for the explorer map/results (public, IP-throttled).
Route::prefix('explore')->middleware('throttle:explore')->group(function () {
    Route::get('city/{city}', [ExploreController::class, 'city'])->name('explore.city');
    Route::get('state/{state}', [ExploreController::class, 'state'])->name('explore.state');
    Route::get('country/{country}', [ExploreController::class, 'country'])->name('explore.country');
});

Route::withHead(robots: 'noindex, nofollow')->middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard')->withHead(title: 'Dashboard');
});

require __DIR__.'/settings.php';
