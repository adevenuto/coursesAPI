<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the `api_requests` detail log.
 *
 * Deliberately separate from App\Support\ApiUsage, which reads the `api_usage`
 * daily rollup: different table, different meaning. This one counts everything
 * including throttled 429s over a rolling window; that one counts allowed calls
 * only, forever, and is what billing reads. Keeping them apart means the billing
 * reader stays untouched — and it's why the two will legitimately disagree.
 *
 * Every range is half-open: >= $from AND < $to. `created_at` and
 * `api_usage.usage_date` are both written in the app timezone (UTC), so
 * DATE(created_at) buckets line up between the two tables.
 */
class ApiAnalytics
{
    /**
     * Shared WHERE shape, so it can't drift between methods.
     */
    private function base(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): Builder
    {
        // Columns are table-qualified because topUsers() joins `users`, which has
        // its own created_at — unqualified, the range predicate is ambiguous.
        $query = DB::table('api_requests')
            ->where('api_requests.created_at', '>=', $from)
            ->where('api_requests.created_at', '<', $to);

        return $userId === null ? $query : $query->where('api_requests.user_id', $userId);
    }

    /**
     * Requests per day, split into ok / errors / throttled, zero-filled.
     *
     * @return list<array{date:string, requests:int, errors:int, throttled:int}>
     */
    public function dailyTraffic(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $rows = $this->base($from, $to, $userId)
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS requests')
            // SUM over a boolean expression is portable across MySQL and MariaDB.
            ->selectRaw('SUM(status >= 400 AND status <> 429) AS errors')
            ->selectRaw('SUM(status = 429) AS throttled')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        return $this->zeroFill($from, $to, fn (string $date) => [
            'date' => $date,
            'requests' => (int) ($rows[$date]->requests ?? 0),
            'errors' => (int) ($rows[$date]->errors ?? 0),
            'throttled' => (int) ($rows[$date]->throttled ?? 0),
        ]);
    }

    /**
     * `errors` excludes 429s, matching totals() and statusBreakdown(). Counting
     * them as errors here would make the endpoint panel disagree with the KPI
     * row on the same page — a throttle is quota pressure, not a failure.
     *
     * @return list<array{endpoint:string, method:string, requests:int, avg_ms:int, max_ms:int, errors:int, throttled:int}>
     */
    public function endpointBreakdown(CarbonInterface $from, CarbonInterface $to, ?int $userId = null, int $limit = 20): array
    {
        return $this->base($from, $to, $userId)
            ->selectRaw('endpoint, method, COUNT(*) AS requests')
            ->selectRaw('ROUND(AVG(duration_ms)) AS avg_ms, MAX(duration_ms) AS max_ms')
            ->selectRaw('SUM(status >= 400 AND status <> 429) AS errors')
            ->selectRaw('SUM(status = 429) AS throttled')
            ->groupBy('endpoint', 'method')
            ->orderByDesc('requests')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'endpoint' => $r->endpoint,
                'method' => $r->method,
                'requests' => (int) $r->requests,
                'avg_ms' => (int) $r->avg_ms,
                'max_ms' => (int) $r->max_ms,
                'errors' => (int) $r->errors,
                'throttled' => (int) $r->throttled,
            ])->all();
    }

    /**
     * Folded into display buckets. 429 is pulled out of 4xx because it's the
     * interesting one — it's the quota-pressure signal, not an error.
     *
     * @return list<array{label:string, count:int}>
     */
    public function statusBreakdown(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $rows = $this->base($from, $to, $userId)
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')
            ->get();

        $buckets = ['Success' => 0, 'Client error' => 0, 'Throttled' => 0, 'Server error' => 0];

        foreach ($rows as $row) {
            $status = (int) $row->status;
            $label = match (true) {
                $status === 429 => 'Throttled',
                $status >= 500 => 'Server error',
                $status >= 400 => 'Client error',
                default => 'Success',
            };
            $buckets[$label] += (int) $row->c;
        }

        return collect($buckets)
            ->filter()
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->values()->all();
    }

    /**
     * Latency percentiles.
     *
     * ROW_NUMBER() rather than PERCENTILE_CONT: MariaDB 11.8 (production) has
     * the latter, MySQL (dev and the test database) does not — so using it would
     * pass in production and fail every test run. Window functions exist on both.
     *
     * @return array{avg:int, p50:int, p95:int, max:int}
     */
    public function latency(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $inner = $this->base($from, $to, $userId)
            ->whereNotNull('duration_ms')
            ->selectRaw('duration_ms')
            ->selectRaw('ROW_NUMBER() OVER (ORDER BY duration_ms) AS rn')
            ->selectRaw('COUNT(*) OVER () AS c');

        $row = DB::query()
            ->fromSub($inner, 't')
            ->selectRaw('ROUND(AVG(duration_ms)) AS avg')
            ->selectRaw('MAX(CASE WHEN rn <= CEIL(c * 0.50) THEN duration_ms END) AS p50')
            ->selectRaw('MAX(CASE WHEN rn <= CEIL(c * 0.95) THEN duration_ms END) AS p95')
            ->selectRaw('MAX(duration_ms) AS max')
            ->first();

        return [
            'avg' => (int) ($row->avg ?? 0),
            'p50' => (int) ($row->p50 ?? 0),
            'p95' => (int) ($row->p95 ?? 0),
            'max' => (int) ($row->max ?? 0),
        ];
    }

    /**
     * @return list<array{date:string, users:int}>
     */
    public function activeUsersDaily(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->base($from, $to)
            ->selectRaw('DATE(created_at) AS d, COUNT(DISTINCT user_id) AS users')
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        return $this->zeroFill($from, $to, fn (string $date) => [
            'date' => $date,
            'users' => (int) ($rows[$date]->users ?? 0),
        ]);
    }

    /**
     * Joined in one query rather than hydrating users per row.
     *
     * @return list<array<string, mixed>>
     */
    public function topUsers(CarbonInterface $from, CarbonInterface $to, int $limit = 10): array
    {
        return $this->base($from, $to)
            ->join('users', 'users.id', '=', 'api_requests.user_id')
            ->selectRaw('users.id, users.name, users.email, users.plan')
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('SUM(status = 429) AS throttled')
            ->selectRaw('MAX(api_requests.created_at) AS last_seen')
            ->groupBy('users.id', 'users.name', 'users.email', 'users.plan')
            ->orderByDesc('requests')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'email' => $r->email,
                'plan' => $r->plan,
                'requests' => (int) $r->requests,
                'throttled' => (int) $r->throttled,
                'last_seen' => Carbon::parse($r->last_seen)->diffForHumans(),
            ])->all();
    }

    /**
     * Who is close to their daily quota — the upgrade-candidate list.
     *
     * Reads `api_usage`, NOT `api_requests`, so the number here matches what the
     * user sees on their own dashboard and what billing counts. The limit itself
     * lives in config, not a column.
     *
     * @return list<array<string, mixed>>
     */
    public function quotaPressure(int $limit = 20): array
    {
        $today = now()->toDateString();

        return DB::table('users')
            ->leftJoin('api_usage', function ($join) use ($today) {
                $join->on('api_usage.user_id', '=', 'users.id')
                    ->where('api_usage.usage_date', '=', $today);
            })
            ->selectRaw('users.id, users.name, users.email, users.plan')
            ->selectRaw('COALESCE(api_usage.requests, 0) AS requests')
            ->having('requests', '>', 0)
            ->orderByDesc('requests')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $plan = $r->plan ?: (string) config('api.default_plan', 'free');
                $quota = (int) config("api.plans.{$plan}.per_day", 0);
                $requests = (int) $r->requests;

                return [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'email' => $r->email,
                    'plan' => $plan,
                    'requests' => $requests,
                    'limit' => $quota,
                    'percent' => $quota > 0 ? min(100, (int) round($requests / $quota * 100)) : 0,
                ];
            })->all();
    }

    /**
     * Cheap because the term was normalised at write time.
     *
     * @return list<array{term:string, count:int}>
     */
    public function topSearchTerms(CarbonInterface $from, CarbonInterface $to, ?int $userId = null, int $limit = 15): array
    {
        return $this->base($from, $to, $userId)
            ->whereNotNull('search_term')
            ->selectRaw('search_term, COUNT(*) AS c')
            ->groupBy('search_term')
            ->orderByDesc('c')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => ['term' => $r->search_term, 'count' => (int) $r->c])
            ->all();
    }

    /**
     * @return list<array{label:string, count:int}>
     */
    public function clientBreakdown(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        return $this->base($from, $to, $userId)
            ->whereNotNull('client')
            ->selectRaw('client, COUNT(*) AS c')
            ->groupBy('client')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($r) => ['label' => $r->client, 'count' => (int) $r->c])
            ->all();
    }

    /**
     * @return array{requests:int, errors:int, throttled:int, unique_ips:int, unique_users:int, avg_ms:int}
     */
    public function totals(CarbonInterface $from, CarbonInterface $to, ?int $userId = null): array
    {
        $row = $this->base($from, $to, $userId)
            ->selectRaw('COUNT(*) AS requests')
            ->selectRaw('SUM(status >= 400 AND status <> 429) AS errors')
            ->selectRaw('SUM(status = 429) AS throttled')
            ->selectRaw('COUNT(DISTINCT ip) AS unique_ips')
            ->selectRaw('COUNT(DISTINCT user_id) AS unique_users')
            ->selectRaw('ROUND(AVG(duration_ms)) AS avg_ms')
            ->first();

        return [
            'requests' => (int) ($row->requests ?? 0),
            'errors' => (int) ($row->errors ?? 0),
            'throttled' => (int) ($row->throttled ?? 0),
            'unique_ips' => (int) ($row->unique_ips ?? 0),
            'unique_users' => (int) ($row->unique_users ?? 0),
            'avg_ms' => (int) ($row->avg_ms ?? 0),
        ];
    }

    /**
     * Per-key usage, reconciled against the user's live tokens.
     *
     * Rows whose token has since been revoked — and rows with no token at all,
     * which is what session-authenticated calls look like — collapse into one
     * labelled bucket rather than showing a bare id the editor can't interpret.
     *
     * @return list<array{token_id:int|null, name:string, requests:int, revoked:bool}>
     */
    public function perKeyBreakdown(int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $this->base($from, $to, $userId)
            ->selectRaw('token_id, MAX(token_name) AS name, COUNT(*) AS c')
            ->groupBy('token_id')
            ->orderByDesc('c')
            ->get();

        $live = DB::table('personal_access_tokens')
            ->where('tokenable_id', $userId)
            ->where('tokenable_type', User::class)
            ->pluck('name', 'id');

        $keys = [];
        $orphaned = 0;

        foreach ($rows as $row) {
            $id = $row->token_id === null ? null : (int) $row->token_id;

            if ($id !== null && $live->has($id)) {
                $keys[] = [
                    'token_id' => $id,
                    'name' => (string) $live[$id],
                    'requests' => (int) $row->c,
                    'revoked' => false,
                ];

                continue;
            }

            $orphaned += (int) $row->c;
        }

        if ($orphaned > 0) {
            $keys[] = [
                'token_id' => null,
                'name' => 'Revoked or session keys',
                'requests' => $orphaned,
                'revoked' => true,
            ];
        }

        return $keys;
    }

    /**
     * Flat token id => count map for the API-keys settings list.
     *
     * @return array<int, int>
     */
    public function requestsByToken(int $userId, int $days = 30): array
    {
        return $this->base(now()->subDays($days)->startOfDay(), now()->addSecond(), $userId)
            ->whereNotNull('token_id')
            ->selectRaw('token_id, COUNT(*) AS c')
            ->groupBy('token_id')
            ->pluck('c', 'token_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Walks whole days across the range so a gap renders as a zero rather than
     * a missing point. Mirrors the loop in ApiUsage::series().
     *
     * @param  callable(string): array<string, mixed>  $make
     * @return list<array<string, mixed>>
     */
    private function zeroFill(CarbonInterface $from, CarbonInterface $to, callable $make): array
    {
        $series = [];
        // Reassigned rather than mutated: the app uses CarbonImmutable, where
        // $cursor->addDay() returns a new instance and loops forever if ignored.
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->subSecond()->startOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $series[] = $make($cursor->toDateString());
            $cursor = $cursor->addDay();
        }

        return $series;
    }
}
