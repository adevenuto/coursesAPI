<?php

namespace Tests\Feature\Admin;

use App\Models\ApiRequest;
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
                ->has('statuses', 0)
                ->has('quota', 0));
    }
}
