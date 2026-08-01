<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ApiKeyController extends Controller
{
    private const MAX_KEYS = 10;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $plan = $user->planConfig();

        $tokens = $user->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'created_at' => $t->created_at?->toDayDateTimeString(),
            'last_used_at' => $t->last_used_at?->diffForHumans(),
        ]);

        return Inertia::render('settings/ApiKeys', [
            'plan' => [
                'key' => $user->planKey(),
                'label' => $plan['label'] ?? ucfirst($user->planKey()),
                'per_day' => (int) ($plan['per_day'] ?? 0),
                'per_minute' => (int) ($plan['per_minute'] ?? 0),
                'premium' => (bool) ($plan['premium'] ?? false),
            ],
            'usage' => [
                'today' => $this->usageToday($user->id),
                'limit' => $user->dailyLimit(),
                'series' => $this->usageSeries($user->id, 14),
            ],
            'tokens' => $tokens,
            'newToken' => $request->session()->get('created_token'),
            'maxKeys' => self::MAX_KEYS,
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

    private function usageToday(int $userId): int
    {
        return (int) DB::table('api_usage')
            ->where('user_id', $userId)
            ->where('usage_date', now()->toDateString())
            ->value('requests');
    }

    /**
     * @return list<array{date:string,requests:int}>
     */
    private function usageSeries(int $userId, int $days): array
    {
        $rows = DB::table('api_usage')
            ->where('user_id', $userId)
            ->where('usage_date', '>=', now()->subDays($days - 1)->toDateString())
            ->pluck('requests', 'usage_date');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'requests' => (int) ($rows[$date] ?? 0)];
        }

        return $series;
    }
}
