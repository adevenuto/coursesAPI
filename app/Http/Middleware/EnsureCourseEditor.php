<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the course editor: the user must be an editor/admin on a paid plan.
 * Unlike EnsurePremium (JSON-only, for the API), this aborts with a 403 so the
 * Inertia error page renders for the web editor flow.
 */
class EnsureCourseEditor
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canEditCourses(), 403);

        return $next($request);
    }
}
