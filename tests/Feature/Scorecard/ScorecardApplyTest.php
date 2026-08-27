<?php

namespace Tests\Feature\Scorecard;

use App\Models\Course;
use App\Models\CourseRevision;
use App\Models\ScorecardScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ScorecardFixture;
use Tests\TestCase;

class ScorecardApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ScorecardScan::DISK);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        return ScorecardFixture::eighteen();
    }

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
    }

    /**
     * @param  array<string, mixed>|null  $card
     */
    private function scanFor(?Course $course, User $editor, ?array $card = null): ScorecardScan
    {
        return ScorecardScan::create([
            'user_id' => $editor->id,
            'course_id' => $course?->id,
            'status' => ScorecardScan::STATUS_PARSED,
            'images' => [],
            'content_hash' => bin2hex(random_bytes(16)),
            'raw_parse' => json_encode($card ?? $this->card()),
            'verification' => ['passed' => true, 'issues' => []],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function course(array $attributes = []): Course
    {
        return Course::create(array_merge([
            'course_name' => 'Bolingbrook Golf Club',
            'lat' => 41.68,
            'lng' => -88.11,
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ], $attributes));
    }

    /**
     * The model's notes were stored from the day the feature shipped and shown
     * nowhere, so a rating left null on purpose — a card that rates eighteen-hole
     * pairings this course isn't one of — reached the editor as an unexplained
     * blank, indistinguishable from a misread.
     */
    public function test_the_review_page_shows_the_readers_notes(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $card = $this->card();
        $card['parseNotes'] = 'RATINGS LEFT NULL: the block rates EAST/SOUTH, SOUTH/WEST and WEST/EAST only.';

        $scan = $this->scanFor($course, $editor, $card);

        $this->actingAs($editor)
            ->get(route('scorecard-scans.show', $scan))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('scan.notes', $card['parseNotes']));
    }

    public function test_a_card_without_notes_reports_none(): void
    {
        $editor = $this->editor();
        $card = $this->card();
        $card['parseNotes'] = '   ';

        $scan = $this->scanFor($this->course(), $editor, $card);

        $this->actingAs($editor)
            ->get(route('scorecard-scans.show', $scan))
            ->assertInertia(fn ($page) => $page->where('scan.notes', null));
    }

    public function test_the_preview_lists_sections_without_touching_the_course(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $response = $this->actingAs($editor)->get("/scorecard-scans/{$scan->id}");

        $diff = $response->viewData('page')['props']['diff'];
        $keys = array_column($diff['sections'], 'key');

        $this->assertContains('details', $keys);
        $this->assertContains('tee:0', $keys);
        $this->assertContains('tee:3', $keys);
        $this->assertFalse($diff['is_new']);

        // Nothing written yet.
        $this->assertSame([], $course->refresh()->layout_data['teeboxes']);
        $this->assertSame(0, CourseRevision::count());
    }

    public function test_applying_selected_sections_writes_only_those(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)
            ->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0', 'tee:1']])
            ->assertRedirect(route('courses.edit', $course));

        $teeboxes = $course->refresh()->layout_data['teeboxes'];

        $this->assertCount(2, $teeboxes);
        $this->assertSame(['Black', 'Blue'], array_column($teeboxes, 'name'));
        $this->assertSame(136, $teeboxes[0]['slope']);
        $this->assertSame(6667, $teeboxes[0]['totalYardage']);
        $this->assertSame('4', ((array) $teeboxes[0]['holes'])['hole-1']['par']);
    }

    public function test_rejecting_a_tee_leaves_the_existing_one_untouched(): void
    {
        $course = $this->course(['layout_data' => [
            'hole_count' => 18,
            'teeboxes' => [[
                'order' => 0,
                'name' => 'Black',
                'slope' => 999,           // deliberately wrong; must survive
                'courseRating' => 60.0,
                'totalYardage' => 1234,
                'holes' => ['hole-1' => ['par' => '3', 'length' => '100']],
            ]],
        ]]);
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        // Accept Blue only — Black was rejected.
        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:1']]);

        $teeboxes = $course->refresh()->layout_data['teeboxes'];

        $this->assertSame(['Black', 'Blue'], array_column($teeboxes, 'name'));
        $this->assertSame(999, $teeboxes[0]['slope'], 'a rejected tee must not be rewritten');
        $this->assertSame(131, $teeboxes[1]['slope']);
    }

    public function test_an_accepted_tee_is_matched_by_name_not_position(): void
    {
        $course = $this->course(['layout_data' => [
            'hole_count' => 18,
            'teeboxes' => [
                ['order' => 0, 'name' => 'Red', 'slope' => 100, 'holes' => []],
                ['order' => 1, 'name' => 'black', 'slope' => 101, 'holes' => []],
            ],
        ]]);
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);

        $teeboxes = $course->refresh()->layout_data['teeboxes'];

        // Still two tees: the scan's Black updated the existing "black" in place,
        // case-insensitively, rather than appending a duplicate.
        $this->assertCount(2, $teeboxes);
        $this->assertSame(100, $teeboxes[0]['slope']);
        $this->assertSame(136, $teeboxes[1]['slope']);
    }

    public function test_course_details_are_applied_only_when_that_section_is_accepted(): void
    {
        $course = $this->course(['course_name' => 'Old Name', 'phone' => '(000) 000-0000']);
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);
        $this->assertSame('Old Name', $course->refresh()->course_name);

        // A second scan rather than re-staging the first: applying twice is
        // rejected, and this is how an editor would actually redo it.
        $again = $this->scanFor($course, $editor);
        $this->actingAs($editor)
            ->post("/scorecard-scans/{$again->id}/apply", ['sections' => ['details']])
            ->assertRedirect();

        $course->refresh();
        $this->assertSame('Bolingbrook Golf Club', $course->course_name);
        $this->assertSame('(630) 771-9400', $course->phone);
    }

    public function test_vendor_keys_and_green_centers_survive_the_apply(): void
    {
        $course = $this->course(['layout_data' => [
            'hole_count' => 18,
            'teeboxes' => [],
            'golftraxx' => ['zip' => '60490', 'matchConfidence' => 0.99],
            'greenCenters' => ['hole-1' => ['lat' => 41.68, 'lng' => -88.11]],
            'greenCentersSource' => 'osm',
        ]]);
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);

        $layout = $course->refresh()->layout_data;

        $this->assertSame(['zip' => '60490', 'matchConfidence' => 0.99], $layout['golftraxx']);
        $this->assertSame(['lat' => 41.68, 'lng' => -88.11], (array) $layout['greenCenters']['hole-1']);
    }

    public function test_apply_records_a_revision_and_links_it_to_the_scan(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['details', 'tee:0']]);

        $scan->refresh();
        $revision = CourseRevision::sole();

        $this->assertSame(ScorecardScan::STATUS_APPLIED, $scan->status);
        $this->assertNotNull($scan->applied_at);
        $this->assertSame($revision->id, $scan->applied_revision_id);
        $this->assertSame('updated', $revision->action);
        $this->assertSame($editor->id, $revision->user_id);
    }

    public function test_a_scan_cannot_be_applied_twice(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);
        $this->actingAs($editor)
            ->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:1']])
            ->assertStatus(409);

        $this->assertCount(1, $course->refresh()->layout_data['teeboxes']);
    }

    public function test_a_scan_with_no_course_creates_one(): void
    {
        $editor = $this->editor();
        $scan = $this->scanFor(null, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", [
            'sections' => ['details', 'layout', 'tee:0', 'tee:1', 'tee:2', 'tee:3'],
        ]);

        $course = Course::sole();

        $this->assertSame('Bolingbrook Golf Club', $course->course_name);
        $this->assertSame(18, $course->layout_data['hole_count']);
        $this->assertCount(4, $course->layout_data['teeboxes']);
        // No coordinates on a scorecard — the editor places it afterwards.
        $this->assertNull($course->lat);
        $this->assertSame($course->id, $scan->refresh()->course_id);
        $this->assertSame('created', CourseRevision::sole()->action);
    }

    public function test_a_nameless_card_cannot_create_a_course(): void
    {
        $card = $this->card();
        $card['name'] = null;

        $editor = $this->editor();
        $scan = $this->scanFor(null, $editor, $card);

        $this->actingAs($editor)
            ->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['details', 'tee:0']])
            ->assertSessionHasErrors('sections');

        $this->assertSame(0, Course::count());
        $this->assertSame(ScorecardScan::STATUS_PARSED, $scan->refresh()->status);
    }

    public function test_out_of_range_values_are_dropped_rather_than_written(): void
    {
        $card = $this->card();
        $card['tees'][0]['slope']['men'] = 210;   // storable range is 55-155
        $card['holes'][0]['par']['men'] = 9;      // storable range is 3-6

        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor, $card);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);

        $teebox = $course->refresh()->layout_data['teeboxes'][0];
        $holes = (array) $teebox['holes'];

        // A misread digit leaves a gap; it never writes layout_data the editor's
        // own save path would refuse.
        $this->assertArrayNotHasKey('slope', array_filter($teebox, fn ($v) => $v !== null));
        $this->assertArrayNotHasKey('par', $holes['hole-1']);
        $this->assertSame('367', $holes['hole-1']['length']);
    }

    public function test_a_nine_hole_cards_ratings_survive_the_apply(): void
    {
        $course = $this->course(['layout_data' => ['hole_count' => 9, 'teeboxes' => []]]);
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor, ScorecardFixture::nine());

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);

        $teebox = $course->refresh()->layout_data['teeboxes'][0];

        // The bug this guards: a flat 55 floor nulled every correct nine-hole
        // rating on the way in, silently.
        // Stored in the gendered [men, women] shape.
        $this->assertSame([33.6, 34.6], $teebox['courseRating']);
    }

    public function test_a_misread_rating_on_a_nine_is_still_dropped(): void
    {
        $card = ScorecardFixture::nine();
        $card['tees'][0]['rating']['women'] = 346; // the decimal point dropped

        $course = $this->course(['layout_data' => ['hole_count' => 9, 'teeboxes' => []]]);
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor, $card);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']]);

        $teebox = $course->refresh()->layout_data['teeboxes'][0];

        // The bound still bites on a nine; the good men's figure is untouched
        // and collapses to a scalar because there's no women's value beside it.
        $this->assertSame(33.6, $teebox['courseRating']);
    }

    public function test_resolved_tee_colours_survive_the_apply(): void
    {
        $card = $this->card();
        $card['tees'][0]['hex'] = '#2C2C2A';  // whatever the model happened to read
        $card['tees'][1]['name'] = 'Blue/White';

        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor, $card);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", [
            'sections' => ['tee:0', 'tee:1'],
        ]);

        $teeboxes = $course->refresh()->layout_data['teeboxes'];

        // Black snapped to the palette rather than keeping the model's shade.
        $this->assertSame('#111827', $teeboxes[0]['color']);
        $this->assertArrayNotHasKey('secondaryColor', $teeboxes[0]);

        $this->assertSame('#1D4ED8', $teeboxes[1]['color']);
        $this->assertSame('#E5E7EB', $teeboxes[1]['secondaryColor']);
    }

    /**
     * The reported case: a card rating Blue and White for men under "Men's
     * Handicap" and Red for women only under "Ladies' Handicap". The parse read
     * 56.1/86 correctly and the preview showed it; the writer then dropped it.
     */
    public function test_a_tee_rated_for_women_only_is_not_dropped_on_apply(): void
    {
        $card = $this->card();
        $card['tees'][3]['name'] = 'Red';
        $card['tees'][3]['rating'] = ['men' => null, 'women' => 56.1];
        $card['tees'][3]['slope'] = ['men' => null, 'women' => 86];

        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor, $card);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:3']]);

        $tee = $course->refresh()->layout_data['teeboxes'][0];

        $this->assertSame('Red', $tee['name']);
        $this->assertSame([null, 56.1], $tee['courseRating']);
        $this->assertSame([null, 86], $tee['slope']);
    }

    public function test_applying_nothing_is_a_no_op(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);

        $this->actingAs($editor)->post("/scorecard-scans/{$scan->id}/apply", ['sections' => []]);

        $this->assertSame([], $course->refresh()->layout_data['teeboxes']);
        $this->assertSame(0, CourseRevision::count());
    }

    public function test_an_unparsed_scan_cannot_be_applied(): void
    {
        $course = $this->course();
        $editor = $this->editor();
        $scan = $this->scanFor($course, $editor);
        $scan->update(['status' => ScorecardScan::STATUS_PENDING, 'raw_parse' => null]);

        $this->actingAs($editor)
            ->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']])
            ->assertStatus(409);
    }

    public function test_only_the_owner_or_an_admin_can_apply(): void
    {
        $course = $this->course();
        $scan = $this->scanFor($course, $this->editor());

        $this->actingAs($this->editor())
            ->post("/scorecard-scans/{$scan->id}/apply", ['sections' => ['tee:0']])
            ->assertForbidden();

        $this->assertSame([], $course->refresh()->layout_data['teeboxes']);
    }
}
