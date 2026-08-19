<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin/analytics')->assertRedirect('/login');
    }

    public function test_a_plain_user_is_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'user', 'plan' => 'max']);

        $this->actingAs($user)->get('/admin/analytics')->assertForbidden();
    }

    public function test_an_editor_on_a_paid_plan_is_still_forbidden(): void
    {
        // canEditCourses() is true here. The admin gate must not be reusing it —
        // an editor has no business seeing other people's usage.
        $editor = User::factory()->create(['role' => 'editor', 'plan' => 'pro']);

        $this->assertTrue($editor->canEditCourses());
        $this->actingAs($editor)->get('/admin/analytics')->assertForbidden();
    }

    public function test_an_admin_can_open_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'plan' => 'free']);

        $this->actingAs($admin)
            ->get('/admin/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Analytics')
                ->where('range', '7d')
                ->has('totals')
                ->has('traffic'));
    }

    public function test_the_admin_flag_is_shared_as_a_derived_boolean(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'plan' => 'free']);
        $user = User::factory()->create(['role' => 'user', 'plan' => 'free']);

        $this->actingAs($admin)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', true));

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', false));
    }
}
