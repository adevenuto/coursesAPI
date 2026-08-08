<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stop the browser serving a cached Inertia JSON payload to a normal page load.
 *
 * An Inertia URL returns HTML for a document navigation and JSON for an XHR
 * carrying the X-Inertia header. `Vary: X-Inertia` is what keeps those as two
 * distinct cache entries, and Inertia's own middleware sets it — but the
 * production stack (LiteSpeed / Hostinger's CDN) replaces the Vary header with
 * `Accept-Encoding` on the way out, so the distinction is lost. The browser then
 * has a single entry per URL and can hand the stored JSON to a page load, which
 * renders as a raw JSON dump.
 *
 * It surfaces most often right after a deploy: the asset version changes, the
 * next visit gets a 409 with X-Inertia-Location, and Inertia forces a hard
 * navigation straight into the poisoned cache entry.
 *
 * Two defences, because the first one alone is not enough here:
 *
 *  1. Re-assert X-Inertia on Vary, appending so we don't drop Accept-Encoding.
 *     Correct, but only helps if the edge stops clobbering it.
 *  2. Send `no-store`, which forbids storing the response at all. This does not
 *     depend on any intermediary preserving a header, and is the right policy
 *     for these pages regardless — they are session-scoped, and the
 *     authenticated ones carry plan, usage and API key data that has no business
 *     sitting in the browser's disk cache.
 *
 * Only HTML documents and Inertia responses are touched; file downloads such as
 * the billing invoice PDFs keep whatever cache headers they set for themselves.
 */
class PreventInertiaResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isInertiaExchange($request, $response)) {
            return $response;
        }

        // false = append rather than replace, so Accept-Encoding survives.
        $response->headers->set('Vary', 'X-Inertia', false);

        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, private, must-revalidate, max-age=0',
        );

        return $response;
    }

    /**
     * Is this a response whose body depends on the X-Inertia request header?
     */
    protected function isInertiaExchange(Request $request, Response $response): bool
    {
        if ($request->headers->has('X-Inertia') || $response->headers->has('X-Inertia')) {
            return true;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
