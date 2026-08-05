<?php

namespace Tests\Feature\Editor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseEditorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/courses/create')->assertRedirect('/login');
    }

    public function test_plain_paid_user_without_editor_role_is_forbidden(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'role' => 'user']);
        $this->actingAs($user)->get('/courses/create')->assertForbidden();
    }

    public function test_editor_on_a_free_plan_is_forbidden(): void
    {
        $user = User::factory()->create(['plan' => 'free', 'role' => 'editor']);
        $this->actingAs($user)->get('/courses/create')->assertForbidden();
    }

    public function test_editor_on_a_paid_plan_can_open_the_editor(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
        $this->actingAs($user)
            ->get('/courses/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('CourseEdit')->where('mode', 'create'));
    }

    public function test_admin_can_edit_without_a_paid_plan(): void
    {
        $user = User::factory()->create(['plan' => 'free', 'role' => 'admin']);
        $this->actingAs($user)->get('/courses/create')->assertOk();
    }

    public function test_explorer_exposes_can_edit_flag(): void
    {
        $editor = User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
        $this->actingAs($editor)
            ->get('/explorer')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', true));
    }
}
