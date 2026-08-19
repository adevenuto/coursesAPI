<?php

namespace App\Http\Middleware;

use App\Support\ApiRequestRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records one `api_requests` row per API call.
 *
 * Sits ABOVE throttle:api so rejected calls are captured — a user repeatedly
 * bouncing off their daily limit is the clearest upgrade signal there is, and it
 * was previously invisible. TrackApiUsage stays below the throttler and is
 * untouched, so the billing rollup still counts allowed calls only. That split is
 * structural rather than conditional: the throttler physically sits between the
 * two, so the rule can't be got wrong later.
 *
 * The insert runs in terminate(), after Response::send() has already called
 * fastcgi_finish_request(), so the caller has their bytes before anything is
 * written and the added latency to the API consumer is zero.
 */
class TrackApiRequest
{
    /** Where the extracted row rides between handle() and terminate(). */
    private const ATTRIBUTE = 'gca.api_request';

    public function __construct(private readonly ApiRequestRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        try {
            // Extracted here, while the request is still intact, but measured
            // before the response is sent so the duration reflects API handling
            // rather than network flush.
            $request->attributes->set(
                self::ATTRIBUTE,
                $this->recorder->extract($request, $response, $startedAt),
            );
        } catch (\Throwable $e) {
            // Analytics must never turn into a 500 for a paying caller. The catch
            // is after $next(), so the real response is returned regardless.
            Log::warning('API analytics extraction failed', ['message' => $e->getMessage()]);
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        // Kernel::terminateMiddleware() resolves a FRESH instance from the
        // container, so nothing set on $this in handle() survives to here. The
        // row has to travel on the request itself.
        $row = $request->attributes->get(self::ATTRIBUTE);

        if (! is_array($row)) {
            return;
        }

        try {
            $this->recorder->store($row);
        } catch (\Throwable $e) {
            Log::warning('API analytics write failed', ['message' => $e->getMessage()]);
        }
    }
}
