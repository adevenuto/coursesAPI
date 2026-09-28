<?php

namespace Tests\Feature\Admin;

use App\Models\ApiRequest;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned so DATE(created_at) bucketing can't straddle midnight UTC.
        $this->travelTo('2026-06-15 12:00:00');

        $this->admin = User::factory()->create(['role' => 'admin', 'plan' => 'pro']);
    }

    public function test_it_reports_totals_and_a_zero_filled_series(): void
    {
        ApiRequest::factory()->count(3)->create(['user_id' => $this->admin->id, 'created_at' => now()]);
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 500, 'created_at' => now()]);
        ApiRequest::factory()->throttled()->create(['user_id' => $this->admin->id, 'created_at' => now()]);
        // Outside the default 7d range.
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'created_at' => now()->subDays(20)]);

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.requests', 5)
                ->where('totals.errors', 1)
                ->where('totals.throttled', 1)
                // 7 days inclusive, oldest first, gaps zero-filled.
                ->has('traffic', 7)
                ->where('traffic.0.date', now()->subDays(6)->toDateString())
                ->where('traffic.0.requests', 0)
                ->where('traffic.6.requests', 5));
    }

    public function test_percentiles_come_from_the_real_distribution(): void
    {
        foreach (range(1, 100) as $milliseconds) {
            ApiRequest::factory()->create([
                'user_id' => $this->admin->id,
                'duration_ms' => $milliseconds,
                'created_at' => now(),
            ]);
        }

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertInertia(fn (Assert $page) => $page
                ->where('latency.p50', 50)
                ->where('latency.p95', 95)
                ->where('latency.max', 100));
    }

    public function test_quota_pressure_reads_the_billing_counter(): void
    {
        $free = User::factory()->create(['plan' => 'free']);

        // Detail rows that must not drive the number.
        ApiRequest::factory()->count(2)->create(['user_id' => $free->id, 'created_at' => now()]);

        DB::table('api_usage')->insert([
            'user_id' => $free->id,
            'usage_date' => now()->toDateString(),
            'requests' => 27,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertInertia(fn (Assert $page) => $page
                ->where('quota.0.requests', 27)
                ->where('quota.0.limit', 30)
                ->where('quota.0.percent', 90));
    }

    public function test_an_unknown_range_falls_back_instead_of_erroring(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/analytics?range=999')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('range', '7d')->has('traffic', 7));
    }

    public function test_the_range_selector_changes_the_window(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/analytics?range=30d')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('range', '30d')->has('traffic', 30));
    }

    public function test_an_empty_period_renders_without_blowing_up(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totals.requests', 0)
                ->where('latency.p95', 0)
                ->has('endpoints', 0)
                ->has('quota', 0)
                // The user-base figures are not range-scoped, so they are
                // present even when the window holds no traffic at all.
                ->where('planMix.total', 1)
                ->has('signupCountries.known', 0));
    }

    /**
     * Response mix and clients were removed from the page, so the controller
     * stops computing them — two queries per load for a prop nobody renders.
     * ApiAnalytics still exposes both, and ApiAnalyticsTest still covers them.
     */
    public function test_the_removed_breakdowns_are_no_longer_sent(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('statuses')->missing('clients'));
    }

    public function test_the_user_base_figures_are_sent(): void
    {
        User::factory()->count(2)->create(['plan' => 'max', 'signup_country' => 'GB']);

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Three paid: these two, plus the pro admin from setUp().
                ->where('planMix.paid', 3)
                ->where('planMix.mrr', round(
                    (float) config('api.plans.pro.price')
                    + 2 * (float) config('api.plans.max.price'),
                    2,
                ))
                ->where('signupCountries.known.0.iso2', 'GB')
                ->where('signupCountries.known.0.users', 2)
                // The admin from setUp() has no country.
                ->where('signupCountries.unknown', 1));
    }

    public function test_the_error_log_endpoint_returns_failed_requests(): void
    {
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 500]);
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 429]);

        $response = $this->actingAs($this->admin)
            ->getJson('/admin/analytics/errors')
            ->assertOk();

        $errors = $response->json('errors');

        $this->assertCount(1, $errors);
        $this->assertSame(500, $errors[0]['status']);
    }

    public function test_the_error_log_can_be_scoped_to_one_endpoint(): void
    {
        ApiRequest::factory()->forEndpoint('api/v1/courses')->create(['user_id' => $this->admin->id, 'status' => 404]);
        ApiRequest::factory()->forEndpoint('api/v1/states')->create(['user_id' => $this->admin->id, 'status' => 404]);

        $errors = $this->actingAs($this->admin)
            ->getJson('/admin/analytics/errors?endpoint='.urlencode('api/v1/states'))
            ->assertOk()
            ->json('errors');

        $this->assertCount(1, $errors);
        $this->assertSame('api/v1/states', $errors[0]['endpoint']);
    }

    /**
     * endpointBreakdown groups by endpoint AND method, so a path served by two
     * verbs has to drill down to the same number the row showed.
     */
    public function test_the_error_log_can_be_scoped_by_method(): void
    {
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 404, 'method' => 'GET']);
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 404, 'method' => 'POST']);

        $errors = $this->actingAs($this->admin)
            ->getJson('/admin/analytics/errors?endpoint='.urlencode('api/v1/courses').'&method=POST')
            ->assertOk()
            ->json('errors');

        $this->assertCount(1, $errors);
        $this->assertSame('POST', $errors[0]['method']);
    }

    /**
     * 429 is not an error anywhere on this page, so the Throttled column needs
     * its own mode rather than a status filter that fights the exclusion.
     */
    public function test_throttled_requests_have_their_own_mode(): void
    {
        ApiRequest::factory()->throttled()->count(2)->create(['user_id' => $this->admin->id]);
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 404]);

        $errors = $this->actingAs($this->admin)->getJson('/admin/analytics/errors')->assertOk();
        $this->assertCount(1, $errors->json('errors'));
        $this->assertSame(404, $errors->json('errors.0.status'));

        $throttled = $this->actingAs($this->admin)
            ->getJson('/admin/analytics/errors?mode=throttled')
            ->assertOk();

        $this->assertCount(2, $throttled->json('errors'));
        $this->assertSame(429, $throttled->json('errors.0.status'));
        $this->assertSame(2, $throttled->json('total'));
    }

    /**
     * The guard against regressing to a client-side tally: the summary counts
     * everything, the rows are only the newest page of it.
     */
    public function test_the_summary_counts_everything_while_the_rows_are_capped(): void
    {
        ApiRequest::factory()->count(60)->create(['user_id' => $this->admin->id, 'status' => 403]);
        ApiRequest::factory()->count(5)->create(['user_id' => $this->admin->id, 'status' => 404]);

        $response = $this->actingAs($this->admin)->getJson('/admin/analytics/errors')->assertOk();

        $this->assertSame(65, $response->json('total'));
        $this->assertCount(50, $response->json('errors'));

        $summary = collect($response->json('summary'))->keyBy('status');
        $this->assertSame(60, $summary[403]['count']);
        $this->assertSame(5, $summary[404]['count']);
        // Ordered by volume, so the dominant cause reads first.
        $this->assertSame(403, $response->json('summary.0.status'));
    }

    public function test_a_status_filter_narrows_within_the_mode(): void
    {
        ApiRequest::factory()->count(2)->create(['user_id' => $this->admin->id, 'status' => 403]);
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 404]);

        $errors = $this->actingAs($this->admin)
            ->getJson('/admin/analytics/errors?status=403')
            ->assertOk()
            ->json('errors');

        $this->assertCount(2, $errors);
        $this->assertSame([403, 403], array_column($errors, 'status'));
    }

    public function test_an_unknown_endpoint_returns_nothing_rather_than_failing(): void
    {
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 404]);

        $response = $this->actingAs($this->admin)
            ->getJson('/admin/analytics/errors?endpoint=api/v1/nope')
            ->assertOk();

        $this->assertSame([], $response->json('errors'));
        $this->assertSame(0, $response->json('total'));
    }

    /** Red is ours, amber is theirs — the table can only tell them apart with this. */
    public function test_the_endpoint_breakdown_separates_server_errors_from_client_errors(): void
    {
        ApiRequest::factory()->create(['user_id' => $this->admin->id, 'status' => 500]);
        ApiRequest::factory()->count(3)->create(['user_id' => $this->admin->id, 'status' => 403]);
        ApiRequest::factory()->throttled()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('endpoints.0.errors', 4)
                ->where('endpoints.0.server_errors', 1)
                ->where('endpoints.0.throttled', 1)
                ->etc());
    }

    /**
     * The course id lives in the `query` JSON because it is a route parameter,
     * so this exercises the JSON_EXTRACT join rather than a plain GROUP BY.
     */
    public function test_it_ranks_the_most_requested_courses(): void
    {
        $wanted = Course::create([
            'course_name' => 'Wanted GC', 'club_name' => 'Wanted Club',
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);
        $other = Course::create([
            'course_name' => 'Quiet GC', 'club_name' => 'Quiet Club',
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);

        ApiRequest::factory()->count(3)->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'api/v1/courses/{course}',
            'query' => ['course' => (string) $wanted->id],
        ]);
        ApiRequest::factory()->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'api/v1/courses/{course}',
            'query' => ['course' => (string) $other->id],
        ]);
        // No route parameter captured — older traffic must simply not appear.
        ApiRequest::factory()->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'api/v1/courses/{course}',
            'query' => null,
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requestedCourses.0.id', $wanted->id)
                ->where('requestedCourses.0.name', 'Wanted GC')
                ->where('requestedCourses.0.count', 3)
                ->where('requestedCourses.1.count', 1)
                ->etc());
    }

    /** Only the 404s, and only from the green-centers endpoint. */
    public function test_it_ranks_the_courses_missing_green_centers(): void
    {
        $course = Course::create([
            'course_name' => 'No Greens GC',
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);

        ApiRequest::factory()->count(2)->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'api/v1/courses/{course}/green-centers',
            'status' => 404,
            'query' => ['course' => (string) $course->id],
        ]);
        // A successful green-centers call isn't a gap.
        ApiRequest::factory()->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'api/v1/courses/{course}/green-centers',
            'status' => 200,
            'query' => ['course' => (string) $course->id],
        ]);
        // Nor is a 404 on a different endpoint.
        ApiRequest::factory()->create([
            'user_id' => $this->admin->id,
            'endpoint' => 'api/v1/courses/{course}',
            'status' => 404,
            'query' => ['course' => (string) $course->id],
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('missingGreenCenters', 1)
                ->where('missingGreenCenters.0.id', $course->id)
                ->where('missingGreenCenters.0.count', 2)
                ->etc());
    }

    public function test_the_error_log_endpoint_is_admin_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'user']))
            ->get('/admin/analytics/errors')
            ->assertForbidden();
    }
}
