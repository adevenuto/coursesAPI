<?php

namespace App\Http\Controllers;

use App\Support\ApiAnalytics;
use App\Support\ApiUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Window for the per-key and per-endpoint panels. */
    private const BREAKDOWN_DAYS = 30;

    public function __invoke(Request $request, ApiUsage $usage, ApiAnalytics $analytics): Response
    {
        $user = $request->user();
        $plan = $user->planConfig();
        $tokens = $user->tokens();
        $recent = $tokens->clone()->latest()->first();

        $to = now()->startOfDay()->addDay();
        $from = now()->subDays(self::BREAKDOWN_DAYS - 1)->startOfDay();

        return Inertia::render('Dashboard', [
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'plan' => [
                'key' => $user->planKey(),
                'label' => $plan['label'] ?? ucfirst($user->planKey()),
                'per_day' => (int) ($plan['per_day'] ?? 0),
                'per_minute' => (int) ($plan['per_minute'] ?? 0),
                'premium' => (bool) ($plan['premium'] ?? false),
            ],
            'usage' => [
                'today' => $usage->today($user->id),
                'limit' => $user->dailyLimit(),
                'series' => $usage->series($user->id, 14),
            ],
            'keys' => [
                'count' => $tokens->count(),
                'recent' => $recent ? [
                    'name' => $recent->name,
                    'last_used_at' => $recent->last_used_at?->diffForHumans(),
                ] : null,
            ],
            // Read from the detail log, which is a different question from
            // `usage` above: that one is the billing view (allowed calls only,
            // kept forever), this is a rolling 30-day breakdown that includes
            // throttled calls. Labelled distinctly in the UI for that reason.
            'breakdown' => [
                'days' => self::BREAKDOWN_DAYS,
                'keys' => $analytics->perKeyBreakdown($user->id, $from, $to),
                'endpoints' => $analytics->endpointBreakdown($from, $to, $user->id, 8),
                'totals' => $analytics->totals($from, $to, $user->id),
            ],
        ]);
    }
}
