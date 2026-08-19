<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\ApiAnalytics;
use App\Support\ApiUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    private const MAX_KEYS = 10;

    /** Matches the dashboard's breakdown window so the two agree. */
    private const USAGE_WINDOW_DAYS = 30;

    public function index(Request $request, ApiUsage $usage, ApiAnalytics $analytics): Response
    {
        $user = $request->user();

        // Keys are named "Production"/"Staging" and until now showed no numbers,
        // so there was no way to tell which one was doing the work.
        $counts = $analytics->requestsByToken($user->id, self::USAGE_WINDOW_DAYS);

        $tokens = $user->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'created_at' => $t->created_at?->toDayDateTimeString(),
            'last_used_at' => $t->last_used_at?->diffForHumans(),
            'requests_30d' => $counts[$t->id] ?? 0,
        ]);

        return Inertia::render('settings/ApiKeys', [
            'usage' => [
                'today' => $usage->today($user->id),
                'limit' => $user->dailyLimit(),
            ],
            'tokens' => $tokens,
            'newToken' => $request->session()->get('created_token'),
            'maxKeys' => self::MAX_KEYS,
            'usageWindowDays' => self::USAGE_WINDOW_DAYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
        ]);

        $user = $request->user();

        if ($user->tokens()->count() >= self::MAX_KEYS) {
            return to_route('api-keys.index')->withErrors([
                'name' => 'You have reached the maximum of '.self::MAX_KEYS.' API keys. Revoke one first.',
            ]);
        }

        $plain = $user->createToken($data['name'])->plainTextToken;

        Inertia::flash('toast', ['type' => 'success', 'message' => 'API key created.']);

        return to_route('api-keys.index')->with('created_token', $plain);
    }

    public function destroy(Request $request, string $tokenId): RedirectResponse
    {
        $request->user()->tokens()->whereKey($tokenId)->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'API key revoked.']);

        return to_route('api-keys.index');
    }
}
