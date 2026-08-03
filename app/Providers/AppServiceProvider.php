<?php

namespace App\Providers;

use App\Listeners\SyncPlanFromStripe;
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

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
