<?php

namespace Database\Seeders;

use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Realistic local traffic for the analytics dashboards.
 *
 * Without this there is nothing to develop against: the real `api_usage` rows
 * predate the 14-day chart window, so the dashboard renders flat zeros.
 *
 * The *shape* matters more than the volume. A uniform random distribution would
 * let real bugs pass inspection — flat hourly traffic hides zero-fill errors,
 * and uniform durations make a broken percentile query look correct. So this
 * deliberately generates diurnal/weekday weighting, a long-tail latency
 * distribution where p50 and p95 differ sharply, a Zipf-ish search-term
 * distribution with a clear head, and enough 429s to make the
 * detail-vs-billing discrepancy visible.
 */
class ApiAnalyticsSeeder extends Seeder
{
    private const DAYS = 60;

    /** endpoint => [weight, method] */
    private const ENDPOINTS = [
        'api/v1/courses' => 60,
        'api/v1/courses/{course}' => 20,
        'api/v1/countries' => 5,
        'api/v1/states' => 5,
        'api/v1/cities' => 5,
        'api/v1/courses/{course}/green-centers' => 4,
        'api/user' => 1,
    ];

    /** status => weight. 429s exist so the "counts here, not toward quota" split is visible. */
    private const STATUSES = [200 => 93, 429 => 3, 404 => 2, 422 => 1, 403 => 1];

    /** user agent => client label */
    private const AGENTS = [
        'curl/8.4.0' => 'curl',
        'python-requests/2.31.0' => 'python',
        'PostmanRuntime/7.36.0' => 'postman',
        'node-fetch/3.3.2' => 'node',
        'GuzzleHttp/7.8' => 'php',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0 Safari/537.36' => 'browser',
    ];

    /** Head-weighted so the "top terms" panel has a clear leader. */
    private const TERMS = [
        'pebble beach' => 30, 'st andrews' => 22, 'pinehurst' => 18, 'bandon dunes' => 14,
        'torrey pines' => 11, 'bethpage' => 9, 'kiawah' => 7, 'whistling straits' => 6,
        'sawgrass' => 5, 'oakmont' => 4, 'royal county down' => 3, 'muirfield' => 2,
    ];

    private const IPS = [
        '203.0.113.14', '203.0.113.77', '198.51.100.23', '198.51.100.9',
        '192.0.2.44', '2001:db8:85a3::8a2e:370:7334', '2001:db8:1f44::22',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed analytics data in production.');

            return;
        }

        $users = $this->users();
        $tokens = $this->tokens($users);

        // Clear only what this seeder owns, so re-running doesn't stack traffic.
        $ids = $users->pluck('id')->all();
        ApiRequest::whereIn('user_id', $ids)->delete();
        DB::table('api_usage')->whereIn('user_id', $ids)->delete();

        $rows = $this->traffic($users, $tokens);

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('api_requests')->insert($chunk);
        }

        $this->backfillUsage($ids);

        $this->command?->info(sprintf(
            'Seeded %s API requests across %d users over %d days.',
            number_format(count($rows)), $users->count(), self::DAYS,
        ));
        $this->command?->line('  Admin login: analytics-admin@example.com / password');
    }

    /**
     * @return Collection<int, User>
     */
    private function users()
    {
        $specs = [
            ['Ada Analytics', 'analytics-admin@example.com', 'pro', 'admin'],
            ['Grace Hopper', 'grace@example.com', 'max', 'user'],
            ['Alan Turing', 'alan@example.com', 'pro', 'user'],
            ['Katherine Johnson', 'katherine@example.com', 'free', 'user'],
            ['Linus Torvalds', 'linus@example.com', 'free', 'user'],
            ['Margaret Hamilton', 'margaret@example.com', 'free', 'user'],
        ];

        return collect($specs)->map(fn (array $s) => User::firstOrCreate(
            ['email' => $s[1]],
            [
                'name' => $s[0],
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'plan' => $s[2],
                'role' => $s[3],
            ],
        ))->each(fn (User $u) => $u->forceFill(['plan' => $u->plan, 'role' => $u->role])->save());
    }

    /**
     * Real Sanctum tokens, so per-key panels group on ids that actually exist.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, list<array{id:int,name:string}>>
     */
    private function tokens($users): array
    {
        $names = ['Production', 'Staging', 'Mobile app'];
        $tokens = [];

        foreach ($users as $i => $user) {
            $user->tokens()->delete();
            $take = ($i % 3) + 1;

            foreach (array_slice($names, 0, $take) as $name) {
                $token = $user->createToken($name)->accessToken;
                $tokens[$user->id][] = ['id' => $token->id, 'name' => $name];
            }
        }

        return $tokens;
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<int, list<array{id:int,name:string}>>  $tokens
     * @return list<array<string, mixed>>
     */
    private function traffic($users, array $tokens): array
    {
        $rows = [];

        foreach ($users as $index => $user) {
            // Rough plan-shaped volume, with the paid accounts doing the work.
            $base = match ($user->plan) {
                'max' => 220,
                'pro' => 90,
                default => 12,
            };

            for ($day = self::DAYS - 1; $day >= 0; $day--) {
                $date = Carbon::today()->subDays($day);

                // Weekday-weighted, with a slow ramp so the trend line rises.
                $weekday = $date->isWeekend() ? 0.45 : 1.0;
                $ramp = 0.55 + (0.45 * (self::DAYS - $day) / self::DAYS);
                // One quiet account that churned halfway through.
                $churn = ($index === 5 && $day < 20) ? 0.0 : 1.0;

                $count = (int) round($base * $weekday * $ramp * $churn * $this->jitter());

                for ($i = 0; $i < $count; $i++) {
                    $rows[] = $this->request($user, $tokens[$user->id] ?? [], $date);
                }
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{id:int,name:string}>  $tokens
     * @return array<string, mixed>
     */
    private function request(User $user, array $tokens, Carbon $date): array
    {
        $endpoint = $this->weighted(self::ENDPOINTS);
        $status = $this->weighted(self::STATUSES);

        // Free plans hit their 30/day ceiling far more often.
        if ($user->plan === 'free' && random_int(1, 100) <= 8) {
            $status = 429;
        }

        // Mirror the real pipeline: the throttler runs before EnsurePremium, so
        // a 429 wins; otherwise a free plan calling green-centers is ALWAYS 403,
        // never sometimes. 403 can't arise anywhere else.
        if ($status !== 429 && $user->plan === 'free' && str_contains($endpoint, 'green-centers')) {
            $status = 403;
        } elseif ($status === 403) {
            $status = 200;
        }

        $agent = array_rand(self::AGENTS);
        $isSearch = $endpoint === 'api/v1/courses' && random_int(1, 100) <= 45;
        $ok = $status === 200;

        // Long tail: ~2% of calls are an order of magnitude slower, which is what
        // makes p50 and p95 diverge enough to catch a broken percentile query.
        $duration = random_int(1, 100) <= 2
            ? random_int(400, 2200)
            : random_int(8, 70);

        $perPage = $this->weighted([25 => 70, 50 => 20, 100 => 10]);
        $results = $ok ? ($endpoint === 'api/v1/courses' ? random_int(0, $perPage) : 1) : null;

        $query = null;
        $term = null;
        if ($isSearch) {
            $term = $this->weighted(self::TERMS);
            $query = ['q' => $term, 'per_page' => $perPage];
        } elseif ($endpoint === 'api/v1/courses') {
            $query = ['country' => 'US', 'per_page' => $perPage];
        }

        $token = $tokens === [] ? null : $tokens[array_rand($tokens)];

        return [
            'user_id' => $user->id,
            'token_id' => $token['id'] ?? null,
            'token_name' => $token['name'] ?? null,
            'method' => 'GET',
            'endpoint' => $endpoint,
            'status' => $status,
            'duration_ms' => $status === 429 ? random_int(1, 6) : $duration,
            'response_bytes' => $ok ? max(180, ($results ?? 1) * random_int(400, 900)) : random_int(90, 240),
            'result_count' => $results,
            'ip' => self::IPS[array_rand(self::IPS)],
            'user_agent' => $agent,
            'client' => self::AGENTS[$agent],
            'search_term' => $term,
            'query' => $query === null ? null : json_encode($query),
            'created_at' => $date->copy()->setTime($this->hour(), random_int(0, 59), random_int(0, 59)),
        ];
    }

    /**
     * Working-hours weighted. Flat traffic would hide zero-fill and bucketing bugs.
     */
    private function hour(): int
    {
        $weights = [
            0 => 1, 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 2, 6 => 4, 7 => 8,
            8 => 14, 9 => 20, 10 => 22, 11 => 21, 12 => 18, 13 => 20, 14 => 22,
            15 => 20, 16 => 16, 17 => 12, 18 => 8, 19 => 6, 20 => 5, 21 => 4,
            22 => 3, 23 => 2,
        ];

        return (int) $this->weighted($weights);
    }

    private function jitter(): float
    {
        return random_int(70, 130) / 100;
    }

    /**
     * @param  array<array-key, int>  $weights
     */
    private function weighted(array $weights): string|int
    {
        $roll = random_int(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /**
     * Rebuild the daily rollup from the generated detail, excluding 429s — the
     * same rule TrackApiUsage follows by sitting below the throttler. Keeping the
     * two consistent means any discrepancy seen while developing is real signal.
     *
     * @param  list<int>  $userIds
     */
    private function backfillUsage(array $userIds): void
    {
        $rows = DB::table('api_requests')
            ->whereIn('user_id', $userIds)
            ->where('status', '<>', 429)
            ->selectRaw('user_id, DATE(created_at) AS usage_date, COUNT(*) AS requests')
            ->groupBy('user_id', 'usage_date')
            ->get();

        $now = now();

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('api_usage')->insert($chunk->map(fn ($r) => [
                'user_id' => $r->user_id,
                'usage_date' => $r->usage_date,
                'requests' => $r->requests,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }
}
