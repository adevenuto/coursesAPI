<?php

namespace App\Http\Controllers;

use App\Support\ApiUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ApiUsage $usage): Response
    {
        $user = $request->user();
        $plan = $user->planConfig();
        $tokens = $user->tokens();
        $recent = $tokens->clone()->latest()->first();

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
        ]);
    }
}
