<?php

namespace Tests\Feature\Editor;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseSiblingsTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
    }

    private function course(array $attrs): Course
    {
        return Course::create(array_merge([
            'course_name' => 'Course',
            'club_name' => 'A Club',
            'lat' => 41.8472222,
            'lng' => -88.1552778,
        ], $attrs));
    }

    public function test_edit_lists_other_courses_sharing_club_and_coordinates(): void
    {
        // Cantigny-like: three routings, same club + coordinates.
        $a = $this->course(['course_name' => 'Woodside/Hillside', 'club_name' => 'Cantigny Golf']);
        $b = $this->course(['course_name' => 'Lakeside/Hillside', 'club_name' => 'Cantigny Golf']);
        $c = $this->course(['course_name' => 'Woodside/Lakeside', 'club_name' => 'Cantigny Golf']);

        // Same coordinates, DIFFERENT club (placeholder-coordinate collision) — must be excluded.
        $this->course(['course_name' => 'Roebourne Golf Club', 'club_name' => 'Roebourne Golf Club']);

        $this->actingAs($this->editor())
            ->get("/courses/{$a->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CourseEdit')
                ->has('siblings', 2)
                ->where('siblings.0.id', $b->id)
                ->where('siblings.0.edit_url', "/courses/{$b->id}/edit")
                ->where('siblings.1.id', $c->id)
            );
    }

    public function test_solo_course_has_no_siblings(): void
    {
        $solo = $this->course([
            'course_name' => 'Lonesome Pines',
            'club_name' => 'Lonesome Pines',
            'lat' => 12.34,
            'lng' => 56.78,
        ]);

        $this->actingAs($this->editor())
            ->get("/courses/{$solo->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('siblings', 0));
    }

    public function test_blank_club_name_is_never_grouped(): void
    {
        // Two courses at identical coordinates but with no club name must not group.
        $a = $this->course(['course_name' => 'No Club A', 'club_name' => '']);
        $this->course(['course_name' => 'No Club B', 'club_name' => '']);

        $this->actingAs($this->editor())
            ->get("/courses/{$a->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('siblings', 0));
    }
}
