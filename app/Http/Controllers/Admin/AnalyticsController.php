<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiAnalytics;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational view of API usage: traffic, endpoints, errors, latency, who is
 * using it and who is near their quota.
 *
 * Reads `api_requests` for everything except quota pressure, which comes from
 * the `api_usage` rollup so the figure matches what the user sees on their own
 * dashboard and what billing counts — and the two user-base figures, plan mix
 * and signup countries, which read `users` and ignore the range entirely.
 *
 * statusBreakdown() and clientBreakdown() are deliberately not requested: the
 * page stopped showing them, and computing a prop nobody renders is two queries
 * per load for nothing. Both remain on ApiAnalytics, still covered by tests, as
 * the obvious source for a per-user view later.
 */
class AnalyticsController extends Controller
{
    /**
     * Range key => days. Capped at the detail-log retention window.
     *
     * No sub-day option: traffic is bucketed by calendar day, so a 24h window
     * renders as two partial bars that read like a trend and aren't one. A
     * shorter view would need hourly bucketing to be honest.
     */
    private const RANGES = ['7d' => 7, '30d' => 30, '90d' => 90];

    public function __invoke(Request $request, ApiAnalytics $analytics): Response
    {
        [$key, $from, $to] = $this->range($request);

        $this->pruneOpportunistically();

        return Inertia::render('admin/Analytics', [
            'range' => $key,
            'ranges' => array_keys(self::RANGES),
            'totals' => $analytics->totals($from, $to),
            'latency' => $analytics->latency($from, $to),
            'traffic' => $analytics->dailyTraffic($from, $to),
            'activeUsers' => $analytics->activeUsersDaily($from, $to),
            'endpoints' => $analytics->endpointBreakdown($from, $to, null, 10),
            'searchTerms' => $analytics->topSearchTerms($from, $to, null, 10),
            // Not range-scoped, unlike everything above: both describe the user
            // base as it stands today, not activity within the window.
            'planMix' => $analytics->planMix(),
            'signupCountries' => $analytics->signupCountries(),
            'topUsers' => $analytics->topUsers($from, $to, 10),
            'quota' => $analytics->quotaPressure(10),
            'retentionDays' => (int) config('api.analytics.retention_days', 90),
        ]);
    }

    /**
     * The failed requests behind the Errors figure, fetched when the log preview
     * is opened rather than shipped with every page load.
     *
     * Most loads never open it, and the rows are the widest thing on the page —
     * putting fifty of them in the Inertia payload unconditionally would cost
     * every visit to save one click.
     */
    public function errors(Request $request, ApiAnalytics $analytics): JsonResponse
    {
        [, $from, $to] = $this->range($request);

        return response()->json([
            'errors' => $analytics->recentErrors($from, $to, 50),
        ]);
    }

    /**
     * Trim one batch of expired detail rows, at most once a day.
     *
     * A stopgap until a cron runs `schedule:run` — the host has none today, and
     * retention is the privacy control for a table holding IPs and search terms,
     * so it can't simply wait. Deliberately bounded and deliberately here rather
     * than in the capture middleware: this fires on an admin page load, never on
     * an API request, so the caller-facing path stays clean.
     */
    private function pruneOpportunistically(): void
    {
        // add() is atomic, so two concurrent loads can't both run it.
        if (! Cache::add('analytics:pruned:'.now()->toDateString(), true, now()->addDay())) {
            return;
        }

        try {
            $cutoff = now()->subDays((int) config('api.analytics.retention_days', 90))->startOfDay();

            DB::table('api_requests')->where('created_at', '<', $cutoff)->limit(5000)->delete();
        } catch (\Throwable) {
            // Housekeeping must never take the page down.
        }
    }

    /**
     * Clamp rather than validate. This is a display toggle on a GET page — a bad
     * querystring should quietly fall back, not bounce the admin to a redirect
     * carrying validation errors.
     *
     * @return array{0: string, 1: CarbonInterface, 2: CarbonInterface}
     */
    private function range(Request $request): array
    {
        $key = (string) $request->query('range', '7d');
        $key = array_key_exists($key, self::RANGES) ? $key : '7d';

        $to = now()->startOfDay()->addDay();
        $from = now()->subDays(self::RANGES[$key] - 1)->startOfDay();

        return [$key, $from, $to];
    }
}
