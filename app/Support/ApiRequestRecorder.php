<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Turns a request/response pair into an `api_requests` row.
 *
 * Split out of the middleware so a capture failure can be tested by binding a
 * throwing stub in the container, rather than by breaking the database mid-test.
 *
 * Only parameters the API actually accepts are stored. Recording the raw query
 * string would let any caller write arbitrary keys of their choosing into the
 * analytics table, which is both a storage and a trust problem. Route parameters
 * are captured on the same terms: their names come from the route definition so
 * a caller cannot invent them, and their values are capped like any other input.
 */
class ApiRequestRecorder
{
    /** Mirrors IndexCoursesRequest + GeoController; anything else is dropped. */
    private const QUERY_WHITELIST = [
        'q', 'country', 'state_prov_id', 'city_id', 'lat', 'lng', 'radius', 'units', 'per_page', 'page',
    ];

    /**
     * Build the row. Cheap enough to run on the request path — the write itself
     * is deferred to terminate().
     *
     * @return array<string, mixed>
     */
    public function extract(Request $request, Response $response, float $startedAt): array
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Attribute on the persisted key, not the class. Sanctum's
        // 'guard' => ['web'] lets a session-authenticated request through
        // carrying a TransientToken, and Sanctum::actingAs() installs a mock
        // PersonalAccessToken — both pass an instanceof check while having no
        // id, which a bare (int) cast would silently record as token 0.
        // is_numeric, not a null check: a TransientToken has no getKey() at all,
        // and Sanctum::actingAs()'s mock returns false for it. Both would sail
        // through a loose check and be recorded as token 0.
        $tokenKey = $token instanceof PersonalAccessToken ? $token->getKey() : null;
        $tokenId = is_numeric($tokenKey) ? (int) $tokenKey : null;

        return [
            'user_id' => $user?->getAuthIdentifier(),
            'token_id' => $tokenId,
            'token_name' => $tokenId === null ? null : Str::limit((string) $token->name, 50, ''),
            'method' => substr($request->method(), 0, 7),
            // The API's routes are unnamed, so uri() is the stable label — and it
            // collapses /courses/17 and /courses/9000 into one bucket.
            'endpoint' => Str::limit($request->route()?->uri() ?? $request->path(), 100, ''),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'response_bytes' => $this->bytes($response),
            'result_count' => $this->resultCount($response),
            'ip' => ApiIp::store($request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
            'client' => ApiClient::label($request->userAgent()),
            'search_term' => $this->searchTerm($request),
            'query' => $this->query($request),
            'created_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function store(array $row): void
    {
        // Query builder, not Eloquent: no model hydration per request, matching
        // how TrackApiUsage writes.
        DB::table('api_requests')->insert($row);
    }

    /**
     * How many records the response carried.
     *
     * Read from the response's *original* content — the resource collection or
     * model that produced the JSON — so nothing is decoded twice. getData() would
     * re-parse the entire payload on every request.
     */
    private function resultCount(Response $response): ?int
    {
        if (! method_exists($response, 'getOriginalContent')) {
            return null;
        }

        $original = $response->getOriginalContent();

        return match (true) {
            $original instanceof Collection, $original instanceof EloquentCollection => $original->count(),
            $original instanceof Model => 1,
            is_array($original) && array_is_list($original) => count($original),
            default => null,
        };
    }

    private function bytes(Response $response): ?int
    {
        if ($response instanceof StreamedResponse) {
            return null;
        }

        $content = $response->getContent();

        return $content === false ? null : strlen($content);
    }

    /**
     * Normalised at write time so "top search terms" stays a plain GROUP BY.
     */
    private function searchTerm(Request $request): ?string
    {
        $q = $request->query('q');

        if (! is_string($q)) {
            return null;
        }

        return Str::limit(Str::lower(trim($q)), 120, '') ?: null;
    }

    /**
     * The route's own parameters — `{course}` and friends — as the caller sent
     * them.
     *
     * `originalParameters()` rather than `parameters()`: the latter hands back
     * bound models, and the case this exists for is the one where binding never
     * happened. A green-centers 404 means a paying customer asked for a course
     * we have no green data for, and without the id the analytics can say that
     * happened 592 times but not once say which course — which is the only part
     * that turns the number into work.
     *
     * @return array<string, string>
     */
    private function routeParams(Request $request): array
    {
        $params = $request->route()?->originalParameters() ?? [];

        $kept = [];

        foreach ($params as $name => $value) {
            // Values are caller-supplied, so they are capped the same way the
            // query whitelist caps its own.
            if (is_scalar($value)) {
                $kept[(string) $name] = Str::limit((string) $value, 40, '');
            }
        }

        return $kept;
    }

    /**
     * @return string|null JSON, or null when nothing survives the whitelist
     */
    private function query(Request $request): ?string
    {
        // Route parameters first, so the path the caller asked for reads ahead
        // of the filters they asked for it with.
        $kept = $this->routeParams($request);

        foreach (self::QUERY_WHITELIST as $key) {
            $value = $request->query($key);

            if ($value === null || is_array($value)) {
                continue;
            }

            // Coordinates are location data — rounding to ~1km keeps the
            // near-me usage signal without pinpointing anyone.
            $kept[$key] = in_array($key, ['lat', 'lng'], true) && is_numeric($value)
                ? round((float) $value, 2)
                : Str::limit((string) $value, 60, '');
        }

        return $kept === [] ? null : json_encode($kept);
    }
}
