<?php

namespace Tests\Feature\Console;

use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneApiRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function requestAt(string $when): ApiRequest
    {
        return ApiRequest::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now()->parse($when),
        ]);
    }

    public function test_it_deletes_rows_past_the_retention_window_and_keeps_the_rest(): void
    {
        config(['api.analytics.retention_days' => 30]);

        $old = $this->requestAt(now()->subDays(45)->toDateTimeString());
        $edge = $this->requestAt(now()->subDays(31)->toDateTimeString());
        $recent = $this->requestAt(now()->subDays(5)->toDateTimeString());

        $this->artisan('api:prune-requests')->assertSuccessful();

        $this->assertDatabaseMissing('api_requests', ['id' => $old->id]);
        $this->assertDatabaseMissing('api_requests', ['id' => $edge->id]);
        $this->assertDatabaseHas('api_requests', ['id' => $recent->id]);
    }

    public function test_the_days_option_overrides_the_configured_window(): void
    {
        config(['api.analytics.retention_days' => 90]);

        $this->requestAt(now()->subDays(20)->toDateTimeString());

        $this->artisan('api:prune-requests', ['--days' => 7])->assertSuccessful();

        $this->assertSame(0, DB::table('api_requests')->count());
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        config(['api.analytics.retention_days' => 30]);

        $this->requestAt(now()->subDays(45)->toDateTimeString());

        $this->artisan('api:prune-requests', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, DB::table('api_requests')->count());
    }

    public function test_chunking_terminates_rather_than_looping(): void
    {
        config(['api.analytics.retention_days' => 10]);

        ApiRequest::factory()->count(12)->create([
            'user_id' => $this->user->id,
            'created_at' => now()->subDays(30),
        ]);

        // A chunk smaller than the row count forces several passes; the loop must
        // still stop rather than spin on a delete that returns zero.
        $this->artisan('api:prune-requests', ['--chunk' => 5])->assertSuccessful();

        $this->assertSame(0, DB::table('api_requests')->count());
    }

    public function test_it_never_touches_the_billing_rollup(): void
    {
        config(['api.analytics.retention_days' => 10]);

        $this->requestAt(now()->subDays(30)->toDateTimeString());

        DB::table('api_usage')->insert([
            'user_id' => $this->user->id,
            'usage_date' => now()->subDays(30)->toDateString(),
            'requests' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('api:prune-requests')->assertSuccessful();

        // The rollup carries no personal data and is the billing record — it is
        // kept indefinitely and must survive every prune.
        $this->assertSame(99, (int) DB::table('api_usage')->value('requests'));
    }

    public function test_a_nonsensical_retention_is_refused(): void
    {
        $this->requestAt(now()->subDays(400)->toDateTimeString());

        $this->artisan('api:prune-requests', ['--days' => 0])->assertFailed();

        $this->assertSame(1, DB::table('api_requests')->count());
    }
}
