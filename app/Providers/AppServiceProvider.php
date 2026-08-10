<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Listeners\SyncPlanFromStripe;
use App\Support\Scorecard\ScorecardParser;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Events\WebhookReceived;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scorecard parsing. Bound (not newed at the call site) so the queued
        // job gets it by method injection and tests can swap in a client with a
        // stubbed transport. The key is still read from config here, once.
        $this->app->bind(ScorecardParser::class, function () {
            $key = (string) config('services.anthropic.key');

            if ($key === '') {
                throw new RuntimeException(
                    'Missing ANTHROPIC_API_KEY. Scorecard scanning needs a server-side Anthropic key.'
                );
            }

            return new ScorecardParser(
                new AnthropicClient(apiKey: $key),
                (string) config('services.anthropic.model'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Mirror Stripe subscription changes onto users.plan.
        Event::listen(WebhookReceived::class, SyncPlanFromStripe::class);
    }

    /**
     * Per-plan API rate limiting: a daily quota + a per-minute burst cap,
     * keyed to the authenticated user (all their keys share the pool).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();

            if (! $user) {
                return Limit::perMinute(20)->by($request->ip());
            }

            // Distinct keys per window — a named limiter that returns multiple
            // limits must key them separately or they collide on one counter.
            return [
                Limit::perDay($user->dailyLimit())->by('api-day:'.$user->id),
                Limit::perMinute($user->burstLimit())->by('api-min:'.$user->id),
            ];
        });

        // Public explorer geo→courses endpoints (unauthenticated, keyed by IP).
        RateLimiter::for('explore', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
