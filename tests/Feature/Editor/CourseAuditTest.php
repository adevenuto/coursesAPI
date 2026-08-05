<?php

namespace Tests\Feature\Editor;

use App\Models\City;
use App\Models\Country;
use App\Models\Course;
use App\Models\CourseRevision;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseAuditTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor', 'name' => 'Ada Editor']);
    }

    private function seedGeoNear(): void
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        State::create(['id' => 10, 'name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'iso2' => 'IL', 'latitude' => 40, 'longitude' => -90]);
        City::create(['id' => 500, 'name' => 'Testville', 'state_id' => 10, 'state_name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US', 'country_name' => 'United States', 'latitude' => 40.0, 'longitude' => -90.0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'course_name' => 'Test Links',
            'club_name' => 'Test Club',
            'lat' => 40.0,
            'lng' => -90.0,
            'hole_count' => 18,
            'teeboxes' => [],
            'green_centers' => [
                ['hole' => 1, 'lat' => 40.0011111, 'lng' => -90.0011111],
                ['hole' => 2, 'lat' => 40.0022222, 'lng' => -90.0022222],
            ],
        ], $overrides);
    }

    public function test_create_records_a_created_revision_and_sets_updated_by(): void
    {
        $this->seedGeoNear();
        $editor = $this->editor();

        $this->actingAs($editor)->post('/courses', $this->payload())->assertRedirect();

        $course = Course::where('course_name', 'Test Links')->firstOrFail();
        $this->assertSame($editor->id, $course->updated_by);

        $rev = CourseRevision::where('course_id', $course->id)->firstOrFail();
        $this->assertSame('created', $rev->action);
        $this->assertSame('Ada Editor', $rev->user_name);
        $this->assertSame('Test Links', $rev->course_name);
    }

    public function test_update_records_the_changes(): void
    {
        $this->seedGeoNear();
        $editor = $this->editor();

        $course = Course::create([
            'course_name' => 'Old Name', 'lat' => 40.0, 'lng' => -90.0,
            'layout_data' => ['greenCenters' => ['hole-1' => ['lat' => 40.5, 'lng' => -90.5]]],
        ]);

        $this->actingAs($editor)->put("/courses/{$course->id}", $this->payload())->assertRedirect();

        $rev = CourseRevision::where('course_id', $course->id)->where('action', 'updated')->firstOrFail();
        $labels = collect($rev->changes)->pluck('label');
        $this->assertTrue($labels->contains('Name'));          // Old Name → Test Links
        $this->assertTrue($labels->contains('Green centers')); // hole 1 moved + hole 2 added
        $this->assertSame($editor->id, $course->fresh()->updated_by);
    }

    public function test_edit_page_exposes_last_editor_and_history(): void
    {
        $this->seedGeoNear();
        $editor = $this->editor();
        $this->actingAs($editor)->post('/courses', $this->payload());
        $course = Course::where('course_name', 'Test Links')->firstOrFail();

        $this->actingAs($editor)
            ->get("/courses/{$course->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('course.last_editor.name', 'Ada Editor')
                ->has('history', 1)
                ->where('history.0.action', 'created'));
    }

    public function test_delete_records_a_revision_that_survives_the_course(): void
    {
        $editor = $this->editor();
        $course = Course::create(['course_name' => 'Doomed CC', 'lat' => 1, 'lng' => 1, 'layout_data' => []]);

        $this->actingAs($editor)->delete("/courses/{$course->id}")->assertRedirect(route('explorer'));

        $this->assertModelMissing($course);
        $rev = CourseRevision::where('action', 'deleted')->firstOrFail();
        $this->assertNull($rev->course_id);          // nulled on course delete
        $this->assertSame('Doomed CC', $rev->course_name); // snapshot survives
    }

    public function test_a_no_op_update_records_nothing(): void
    {
        $this->seedGeoNear();
        $editor = $this->editor();
        $this->actingAs($editor)->post('/courses', $this->payload());
        $course = Course::where('course_name', 'Test Links')->firstOrFail();

        // Submit the identical payload → no change → no new revision.
        $this->actingAs($editor)->put("/courses/{$course->id}", $this->payload())->assertRedirect();

        $this->assertSame(0, CourseRevision::where('action', 'updated')->count());
        $this->assertSame(1, CourseRevision::where('course_id', $course->id)->count()); // just the create
    }
}
