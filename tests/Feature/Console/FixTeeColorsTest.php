<?php

namespace Tests\Feature\Console;

use App\Models\Course;
use App\Models\CourseRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixTeeColorsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $teeboxes
     */
    private function course(array $teeboxes, string $name = 'Test Links'): Course
    {
        return Course::create([
            'course_name' => $name,
            'lat' => 40.0,
            'lng' => -90.0,
            'layout_data' => ['hole_count' => 18, 'teeboxes' => $teeboxes],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function tee(string $name, ?string $color = null, ?string $secondary = null): array
    {
        return [
            'order' => 0,
            'name' => $name,
            'color' => $color,
            'secondaryColor' => $secondary,
            'holes' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teeboxes(Course $course): array
    {
        return $course->refresh()->layout_data['teeboxes'];
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $course = $this->course([$this->tee('Blue')]);

        $this->artisan('courses:fix-tee-colors')
            ->assertSuccessful();

        $this->assertNull($this->teeboxes($course)[0]['color']);
    }

    public function test_apply_fills_a_colour_from_the_name(): void
    {
        $course = $this->course([$this->tee('Blue'), $this->tee('Burgundy'), $this->tee('Azul')]);

        $this->artisan('courses:fix-tee-colors --apply')->assertSuccessful();

        $teeboxes = $this->teeboxes($course);
        $this->assertSame('#1D4ED8', $teeboxes[0]['color']);
        $this->assertSame('#800020', $teeboxes[1]['color']);
        $this->assertSame('#1D4ED8', $teeboxes[2]['color']);
    }

    public function test_a_two_tone_name_fills_the_second_colour(): void
    {
        $course = $this->course([$this->tee('Blue/White')]);

        $this->artisan('courses:fix-tee-colors --apply')->assertSuccessful();

        $tee = $this->teeboxes($course)[0];
        $this->assertSame('#1D4ED8', $tee['color']);
        $this->assertSame('#E5E7EB', $tee['secondaryColor']);
    }

    public function test_a_colour_already_set_is_left_alone(): void
    {
        // Someone picked this by hand. A backfill must not have an opinion.
        $course = $this->course([$this->tee('Blue', '#0000FF')]);

        $this->artisan('courses:fix-tee-colors --apply')->assertSuccessful();

        $this->assertSame('#0000FF', $this->teeboxes($course)[0]['color']);
    }

    public function test_overwrite_replaces_an_existing_colour(): void
    {
        $course = $this->course([$this->tee('Blue', '#0000FF')]);

        $this->artisan('courses:fix-tee-colors --apply --overwrite')->assertSuccessful();

        $this->assertSame('#1D4ED8', $this->teeboxes($course)[0]['color']);
    }

    public function test_a_name_with_no_colour_is_skipped(): void
    {
        $course = $this->course([$this->tee('Championship'), $this->tee('')]);

        $this->artisan('courses:fix-tee-colors --apply')->assertSuccessful();

        $teeboxes = $this->teeboxes($course);
        $this->assertNull($teeboxes[0]['color']);
        $this->assertNull($teeboxes[1]['color']);
    }

    /**
     * A mechanical pass over 22,000 courses would bury the hand edits that make
     * the audit log worth reading.
     */
    public function test_it_records_no_revisions(): void
    {
        $this->course([$this->tee('Blue')]);

        $this->artisan('courses:fix-tee-colors --apply')->assertSuccessful();

        $this->assertSame(0, CourseRevision::count());
    }

    public function test_limit_stops_early(): void
    {
        $first = $this->course([$this->tee('Blue')], 'First');
        $second = $this->course([$this->tee('Red')], 'Second');

        $this->artisan('courses:fix-tee-colors --apply --limit=1')->assertSuccessful();

        $this->assertSame('#1D4ED8', $this->teeboxes($first)[0]['color']);
        $this->assertNull($this->teeboxes($second)[0]['color']);
    }

    /**
     * 177 production courses store `"holes":{}`. Decoding to an associative
     * array and saving would silently rewrite those as `[]` — harmless to read,
     * but not this command's business to change.
     */
    public function test_an_empty_holes_object_stays_an_object(): void
    {
        $course = $this->course([$this->tee('Blue')]);

        DB::table('courses')->where('id', $course->id)->update([
            'layout_data' => '{"hole_count":18,"teeboxes":[{"order":0,"name":"Blue","holes":{}}]}',
        ]);

        $this->artisan('courses:fix-tee-colors --apply')->assertSuccessful();

        $raw = (string) DB::table('courses')->where('id', $course->id)->value('layout_data');

        $this->assertStringContainsString('"holes":{}', $raw);
        $this->assertStringContainsString('#1D4ED8', $raw);
    }

    public function test_the_csv_lists_every_proposed_change(): void
    {
        $this->course([$this->tee('Blue'), $this->tee('White/Gold')]);
        $path = tempnam(sys_get_temp_dir(), 'tee-colors').'.csv';

        $this->artisan("courses:fix-tee-colors --csv={$path}")->assertSuccessful();

        $csv = (string) file_get_contents($path);
        @unlink($path);

        // A dry run still reports — that's the point of reading it first.
        $this->assertStringContainsString('course_id,course_name,tee', $csv);
        $this->assertStringContainsString('#1D4ED8', $csv);
        $this->assertStringContainsString('#CA8A04', $csv);
    }
}
