<?php

namespace Tests\Feature;

use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_the_cockpit()
    {
        $user = User::factory()->create(['plan' => 'pro']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('plan.key', 'pro')
                ->where('usage.limit', 10000)
                ->has('usage.series', 14)
                ->where('keys.count', 0),
            );
    }

    public function test_the_dashboard_breaks_usage_down_by_key_and_endpoint()
    {
        $user = User::factory()->create(['plan' => 'pro']);
        $token = $user->createToken('Production')->accessToken;

        ApiRequest::factory()->count(4)->create([
            'user_id' => $user->id,
            'token_id' => $token->id,
            'token_name' => 'Production',
            'endpoint' => 'api/v1/courses',
        ]);
        ApiRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'token_id' => $token->id,
            'token_name' => 'Production',
            'endpoint' => 'api/v1/cities',
        ]);
        // Throttled calls belong in the breakdown but never in the billing view.
        ApiRequest::factory()->throttled()->create([
            'user_id' => $user->id,
            'token_id' => $token->id,
            'token_name' => 'Production',
            'endpoint' => 'api/v1/courses',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('breakdown.days', 30)
                ->has('breakdown.keys', 1)
                ->where('breakdown.keys.0.name', 'Production')
                ->where('breakdown.keys.0.requests', 7)
                ->has('breakdown.endpoints', 2)
                ->where('breakdown.endpoints.0.endpoint', 'api/v1/courses')
                ->where('breakdown.endpoints.0.requests', 5)
                ->where('breakdown.totals.throttled', 1)
                // The billing view stays its own number and is unaffected.
                ->where('usage.today', 0),
            );
    }

    public function test_a_user_with_no_traffic_gets_empty_breakdowns_not_an_error()
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('breakdown.keys', 0)
                ->has('breakdown.endpoints', 0)
                ->where('breakdown.totals.requests', 0),
            );
    }
}
