<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the admin area: the user must hold the admin role outright.
 *
 * Deliberately not canEditCourses() — an editor on a paid plan passes that and
 * must NOT reach operational analytics about other people's usage. Aborts with a
 * 403 so the Inertia error page renders, matching EnsureCourseEditor rather than
 * EnsurePremium's JSON response.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
