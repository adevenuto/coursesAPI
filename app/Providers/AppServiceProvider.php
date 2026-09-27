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
use Illuminate\Support\Str;
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

        // Public geo name lookups behind the suggestion modal's typeaheads. Higher
        // than `explore` because a typeahead fires per keystroke-burst across
        // three fields, and separate from it so working the modal can't spend the
        // budget the map needs. Every response is capped and every query is
        // parent-scoped, so this ceiling is about server cost — the underlying
        // dr5hn geo dataset is public and has nothing to protect.
        RateLimiter::for('geo', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Public "suggest a course" form (unauthenticated). Three windows: a
        // burst guard, a daily ceiling, and an email-keyed limit — the only lever
        // against a spammer rotating IPs, mirroring how Fortify's `login` limiter
        // pairs the submitted identifier with the address. Keyed separately for
        // the reason spelled out on the `api` limiter above.
        //
        // Sized on the assumption that throttling happens BEFORE validation, so
        // every fumbled submission spends a slot too. Someone correcting a
        // mistyped email three times must not be locked out, and the golfer who
        // knows eight missing courses in their county is the best user this form
        // has — neither should ever meet a 429.
        RateLimiter::for('suggestions', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('submitter_email')));

            return [
                Limit::perMinute(5)->by('suggest-min:'.$request->ip()),
                Limit::perDay(40)->by('suggest-day:'.$request->ip()),
                // Only when an address was actually given. Keying on '' would put
                // every blank-email attempt into ONE bucket shared by everybody,
                // so five validation fumbles — from any five visitors — would
                // lock the form site-wide for an hour. The per-IP windows above
                // are what cover an anonymous flood.
                $email === ''
                    ? Limit::none()
                    : Limit::perHour(15)->by('suggest-email:'.Str::limit($email, 120, '')),
            ];
        });
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
