<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocsController extends Controller
{
    /**
     * Public API documentation. Plan limits come straight from config so the
     * rate-limit tables never drift from what the API actually enforces.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Docs', [
            'plans' => config('api.plans'),
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'pagination' => config('api.pagination'),
            'maxRadiusKm' => (int) config('api.max_radius_km', 100),
        ]);
    }
}
