<?php

namespace Tests\Feature\Api;

use App\Models\ApiRequest;
use App\Models\User;
use App\Support\ApiAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private ApiAnalytics $analytics;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = app(ApiAnalytics::class);
        $this->user = User::factory()->create(['plan' => 'pro']);

        // Pin the clock so DATE(created_at) bucketing is deterministic and a run
        // near midnight UTC can't shuffle rows into the wrong day.
        $this->travelTo('2026-06-15 12:00:00');
    }

    private function rangeStart(int $daysAgo = 6)
    {
        return now()->subDays($daysAgo)->startOfDay();
    }

    private function rangeEnd()
    {
        return now()->startOfDay()->addDay();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function request(array $attributes = []): ApiRequest
    {
        return ApiRequest::factory()->create(array_merge(['user_id' => $this->user->id], $attributes));
    }

    public function test_daily_traffic_zero_fills_the_whole_range(): void
    {
        $this->request(['created_at' => now()->subDays(2)]);
        $this->request(['created_at' => now()->subDays(2)]);

        $series = $this->analytics->dailyTraffic($this->rangeStart(), $this->rangeEnd());

        // 7 days inclusive, oldest first, gaps present as zeros rather than holes.
        $this->assertCount(7, $series);
        $this->assertSame(now()->subDays(6)->toDateString(), $series[0]['date']);
        $this->assertSame(now()->toDateString(), $series[6]['date']);
        $this->assertSame(0, $series[0]['requests']);
        $this->assertSame(2, $series[4]['requests']);
    }

    public function test_daily_traffic_separates_errors_from_throttles(): void
    {
        $this->request(['status' => 200]);
        $this->request(['status' => 404]);
        $this->request(['status' => 500]);
        $this->request(['status' => 429]);

        $today = collect($this->analytics->dailyTraffic($this->rangeStart(), $this->rangeEnd()))->last();

        $this->assertSame(4, $today['requests']);
        // A throttle is quota pressure, not a failure — never both.
        $this->assertSame(2, $today['errors']);
        $this->assertSame(1, $today['throttled']);
    }

    public function test_the_range_is_half_open(): void
    {
        $from = now()->startOfDay();
        $to = now()->addDay()->startOfDay();

        $this->request(['created_at' => $from->copy()->subSecond()]);  // just before
        $this->request(['created_at' => $from]);                        // included
        $this->request(['created_at' => $to->copy()->subSecond()]);     // included
        $this->request(['created_at' => $to]);                          // just after — excluded

        $this->assertSame(2, $this->analytics->totals($from, $to)['requests']);
    }

    public function test_percentiles_are_computed_from_the_real_distribution(): void
    {
        // 1..100ms. p50 = 50, p95 = 95 by construction, so a broken percentile
        // query can't coincidentally look right.
        foreach (range(1, 100) as $ms) {
            $this->request(['duration_ms' => $ms]);
        }

        $latency = $this->analytics->latency($this->rangeStart(), $this->rangeEnd());

        $this->assertSame(50, $latency['p50']);
        $this->assertSame(95, $latency['p95']);
        $this->assertSame(100, $latency['max']);
        $this->assertSame(51, $latency['avg']);
    }

    public function test_latency_is_zero_rather_than_null_when_there_is_nothing_to_measure(): void
    {
        $latency = $this->analytics->latency($this->rangeStart(), $this->rangeEnd());

        $this->assertSame(['avg' => 0, 'p50' => 0, 'p95' => 0, 'max' => 0], $latency);
    }

    public function test_endpoint_error_counts_agree_with_the_totals(): void
    {
        $this->request(['endpoint' => 'api/v1/courses', 'status' => 200]);
        $this->request(['endpoint' => 'api/v1/courses', 'status' => 404]);
        $this->request(['endpoint' => 'api/v1/courses', 'status' => 429]);
        $this->request(['endpoint' => 'api/v1/cities', 'status' => 200]);

        $totals = $this->analytics->totals($this->rangeStart(), $this->rangeEnd());
        $endpoints = $this->analytics->endpointBreakdown($this->rangeStart(), $this->rangeEnd());

        // The endpoint panel and the KPI row sit on the same page; if these two
        // ever disagree it reads as a bug in the numbers.
        $this->assertSame($totals['errors'], array_sum(array_column($endpoints, 'errors')));
        $this->assertSame($totals['throttled'], array_sum(array_column($endpoints, 'throttled')));
        $this->assertSame('api/v1/courses', $endpoints[0]['endpoint']);
        $this->assertSame(3, $endpoints[0]['requests']);
    }

    public function test_status_buckets_account_for_every_request(): void
    {
        foreach ([200, 201, 404, 422, 429, 500] as $status) {
            $this->request(['status' => $status]);
        }

        $buckets = collect($this->analytics->statusBreakdown($this->rangeStart(), $this->rangeEnd()))
            ->pluck('count', 'label');

        $this->assertSame(2, $buckets['Success']);
        $this->assertSame(2, $buckets['Client error']);
        $this->assertSame(1, $buckets['Throttled']);
        $this->assertSame(1, $buckets['Server error']);
        $this->assertSame(6, $buckets->sum());
    }

    public function test_top_users_ranks_by_volume_and_joins_the_user(): void
    {
        $quiet = User::factory()->create(['plan' => 'free', 'name' => 'Quiet']);

        ApiRequest::factory()->count(5)->create(['user_id' => $this->user->id]);
        ApiRequest::factory()->count(2)->create(['user_id' => $quiet->id]);

        $top = $this->analytics->topUsers($this->rangeStart(), $this->rangeEnd());

        $this->assertSame($this->user->id, $top[0]['id']);
        $this->assertSame(5, $top[0]['requests']);
        $this->assertSame('pro', $top[0]['plan']);
        $this->assertSame('Quiet', $top[1]['name']);
    }

    public function test_quota_pressure_reads_the_billing_rollup_not_the_detail_log(): void
    {
        $free = User::factory()->create(['plan' => 'free']);

        // Detail rows that must NOT drive this number.
        ApiRequest::factory()->count(9)->create(['user_id' => $free->id]);

        DB::table('api_usage')->insert([
            'user_id' => $free->id,
            'usage_date' => now()->toDateString(),
            'requests' => 24,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = collect($this->analytics->quotaPressure())->firstWhere('id', $free->id);

        // 24 from the rollup, not 9 from the detail — so the admin sees the same
        // figure the user sees and billing counts.
        $this->assertSame(24, $row['requests']);
        $this->assertSame(30, $row['limit']);
        $this->assertSame(80, $row['percent']);
    }

    public function test_per_key_breakdown_folds_revoked_and_session_traffic_together(): void
    {
        $live = $this->user->createToken('Production')->accessToken;

        ApiRequest::factory()->count(4)->create([
            'user_id' => $this->user->id,
            'token_id' => $live->id,
            'token_name' => 'Production',
        ]);
        // A key that has since been revoked.
        ApiRequest::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'token_id' => 9999,
            'token_name' => 'Old Staging',
        ]);
        // Session-authenticated traffic carries no token at all.
        ApiRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'token_id' => null,
        ]);

        $keys = $this->analytics->perKeyBreakdown($this->user->id, $this->rangeStart(), $this->rangeEnd());

        $this->assertCount(2, $keys);
        $this->assertSame('Production', $keys[0]['name']);
        $this->assertSame(4, $keys[0]['requests']);
        $this->assertFalse($keys[0]['revoked']);

        // Both orphan cases collapse into one labelled bucket rather than
        // surfacing an id nobody can interpret.
        $this->assertTrue($keys[1]['revoked']);
        $this->assertSame(5, $keys[1]['requests']);
        $this->assertNull($keys[1]['token_id']);
    }

    public function test_requests_by_token_returns_a_flat_map(): void
    {
        $token = $this->user->createToken('Production')->accessToken;

        ApiRequest::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'token_id' => $token->id,
        ]);
        ApiRequest::factory()->create(['user_id' => $this->user->id, 'token_id' => null]);

        $map = $this->analytics->requestsByToken($this->user->id);

        $this->assertSame([$token->id => 3], $map);
    }

    public function test_search_terms_and_clients_rank_by_frequency(): void
    {
        ApiRequest::factory()->count(3)->create(['user_id' => $this->user->id, 'search_term' => 'pebble']);
        ApiRequest::factory()->create(['user_id' => $this->user->id, 'search_term' => 'oakmont']);
        ApiRequest::factory()->count(2)->create(['user_id' => $this->user->id, 'client' => 'python']);

        $terms = $this->analytics->topSearchTerms($this->rangeStart(), $this->rangeEnd());
        $clients = collect($this->analytics->clientBreakdown($this->rangeStart(), $this->rangeEnd()))->pluck('count', 'label');

        $this->assertSame(['term' => 'pebble', 'count' => 3], $terms[0]);
        $this->assertSame('oakmont', $terms[1]['term']);
        $this->assertSame(2, $clients['python']);
        $this->assertSame(4, $clients['curl']); // the factory default
    }

    public function test_everything_is_scoped_when_a_user_is_given(): void
    {
        $other = User::factory()->create();

        ApiRequest::factory()->count(3)->create(['user_id' => $this->user->id]);
        ApiRequest::factory()->count(7)->create(['user_id' => $other->id]);

        $this->assertSame(3, $this->analytics->totals($this->rangeStart(), $this->rangeEnd(), $this->user->id)['requests']);
        $this->assertSame(10, $this->analytics->totals($this->rangeStart(), $this->rangeEnd())['requests']);
    }

    public function test_active_users_per_day_counts_distinct_users(): void
    {
        $other = User::factory()->create();

        ApiRequest::factory()->count(4)->create(['user_id' => $this->user->id, 'created_at' => now()]);
        ApiRequest::factory()->create(['user_id' => $other->id, 'created_at' => now()]);

        $series = $this->analytics->activeUsersDaily($this->rangeStart(), $this->rangeEnd());

        $this->assertCount(7, $series);
        $this->assertSame(2, collect($series)->last()['users']);
        $this->assertSame(0, $series[0]['users']);
    }
}
