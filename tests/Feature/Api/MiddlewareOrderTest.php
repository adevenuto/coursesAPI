<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\TrackApiRequest;
use App\Http\Middleware\TrackApiUsage;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The API middleware order decides what analytics can see, and getting it wrong
 * fails silently — throttled requests simply stop being recorded.
 *
 * Listing the middleware in order in routes/api.php is not sufficient: Laravel
 * re-sorts route middleware by its priority list, which hoists ThrottleRequests
 * above anything unlisted. bootstrap/app.php pins TrackApiRequest ahead of it.
 * This test fails if that pin is ever removed.
 */
class MiddlewareOrderTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function resolvedMiddleware(string $uri): array
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === $uri);

        $this->assertNotNull($route, "No route registered for {$uri}");

        return array_map(
            fn ($m) => is_string($m) ? $m : $m::class,
            app('router')->gatherRouteMiddleware($route),
        );
    }

    private function positionOf(array $middleware, string $needle): int
    {
        foreach ($middleware as $i => $name) {
            if (str_starts_with($name, $needle)) {
                return $i;
            }
        }

        $this->fail("{$needle} is not in the resolved middleware stack: ".implode(', ', $middleware));
    }

    /**
     * @return list<array{0:string}>
     */
    public static function apiRoutes(): array
    {
        return [
            ['api/user'],
            ['api/v1/courses'],
            ['api/v1/courses/{course}'],
            ['api/v1/courses/{course}/green-centers'],
            ['api/v1/countries'],
            ['api/v1/states'],
            ['api/v1/cities'],
        ];
    }

    #[DataProvider('apiRoutes')]
    public function test_capture_runs_before_the_throttler_and_billing_after_it(string $uri): void
    {
        $middleware = $this->resolvedMiddleware($uri);

        $capture = $this->positionOf($middleware, TrackApiRequest::class);
        $throttle = $this->positionOf($middleware, ThrottleRequests::class);
        $billing = $this->positionOf($middleware, TrackApiUsage::class);

        // Detail log sees rejections...
        $this->assertLessThan($throttle, $capture, "TrackApiRequest must run BEFORE the throttler on {$uri}");
        // ...while the billing rollup stays unreachable on a 429, as it always was.
        $this->assertGreaterThan($throttle, $billing, "TrackApiUsage must run AFTER the throttler on {$uri}");
    }

    #[DataProvider('apiRoutes')]
    public function test_every_api_route_is_authenticated_throttled_and_tracked(string $uri): void
    {
        $middleware = $this->resolvedMiddleware($uri);

        // /api/user previously had none of the last three.
        $this->positionOf($middleware, 'Illuminate\Auth\Middleware\Authenticate');
        $this->positionOf($middleware, ThrottleRequests::class);
        $this->positionOf($middleware, TrackApiRequest::class);
        $this->positionOf($middleware, TrackApiUsage::class);
    }
}
