<?php

namespace Tests\Feature\Scorecard;

use App\Models\Course;
use App\Models\ScorecardScan;
use App\Models\User;
use App\Support\Scorecard\ScorecardImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScorecardUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ScorecardScan::DISK);
    }

    private function editor(): User
    {
        return User::factory()->create(['plan' => 'pro', 'role' => 'editor']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/scorecard-scans/create')->assertRedirect('/login');
    }

    public function test_non_editor_is_forbidden(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'role' => 'user']);

        $this->actingAs($user)->get('/scorecard-scans/create')->assertForbidden();
    }

    public function test_editor_can_open_the_upload_page(): void
    {
        $this->actingAs($this->editor())
            ->get('/scorecard-scans/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ScorecardScan')
                ->where('scan', null)
                ->where('course', null));
    }

    public function test_upload_creates_a_pending_scan_with_normalized_images(): void
    {
        $editor = $this->editor();

        $response = $this->actingAs($editor)->post('/scorecard-scans', [
            'images' => [UploadedFile::fake()->image('front.jpg', 1200, 800)],
        ]);

        $scan = ScorecardScan::sole();

        $response->assertRedirect(route('scorecard-scans.show', $scan));

        $this->assertSame(ScorecardScan::STATUS_PENDING, $scan->status);
        $this->assertSame($editor->id, $scan->user_id);
        $this->assertNull($scan->course_id);
        $this->assertCount(1, $scan->images);
        $this->assertNotSame('', $scan->content_hash);

        $image = $scan->images[0];
        $this->assertSame('front.jpg', $image['original_name']);
        $this->assertSame('image/jpeg', $image['mime']);
        $this->assertSame(1200, $image['width']);
        Storage::disk(ScorecardScan::DISK)->assertExists($image['path']);
    }

    public function test_oversized_images_are_downscaled_to_the_vision_ceiling(): void
    {
        $this->actingAs($this->editor())->post('/scorecard-scans', [
            'images' => [UploadedFile::fake()->image('huge.jpg', 4000, 3000)],
        ]);

        $image = ScorecardScan::sole()->images[0];

        // Long edge clamped, aspect ratio preserved.
        $this->assertSame(ScorecardImage::MAX_EDGE, $image['width']);
        $this->assertSame((int) round(3000 * (ScorecardImage::MAX_EDGE / 4000)), $image['height']);
    }

    public function test_scan_can_be_attached_to_an_existing_course(): void
    {
        $course = $this->makeCourse();

        $this->actingAs($this->editor())->post('/scorecard-scans', [
            'images' => [UploadedFile::fake()->image('card.jpg')],
            'course_id' => $course->id,
        ]);

        $this->assertSame($course->id, ScorecardScan::sole()->course_id);
    }

    public function test_identical_images_produce_the_same_content_hash(): void
    {
        $editor = $this->editor();

        // Same synthetic image twice — the normalised bytes must hash alike so a
        // re-upload can reuse the earlier parse instead of paying again.
        foreach ([1, 2] as $_) {
            $this->actingAs($editor)->post('/scorecard-scans', [
                'images' => [UploadedFile::fake()->image('card.jpg', 900, 600)],
            ]);
        }

        $hashes = ScorecardScan::pluck('content_hash')->unique();

        $this->assertCount(1, $hashes);
    }

    /**
     * The parse is no longer a pure function of the images: the prompt names the
     * course being read so the model can pick the right section out of a ratings
     * block covering several pairings. A 27-hole facility's nine card belongs to
     * two courses legitimately, so a hash keyed on bytes alone would hand the
     * second course the first one's ratings via the reuse path.
     */
    public function test_the_same_card_hashes_differently_per_course(): void
    {
        $editor = $this->editor();
        $courses = collect(['East/South', 'South/West'])->map(fn (string $name) => Course::create([
            'course_name' => $name,
            'club_name' => 'Monterey Country Club',
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]));

        foreach ($courses as $course) {
            $this->actingAs($editor)->post('/scorecard-scans', [
                'images' => [UploadedFile::fake()->image('card.jpg', 900, 600)],
                'course_id' => $course->id,
            ]);
        }

        $this->assertCount(2, ScorecardScan::pluck('content_hash')->unique());
    }

    public function test_upload_validation_rejects_bad_input(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post('/scorecard-scans', [])
            ->assertSessionHasErrors('images');

        $this->actingAs($editor)
            ->post('/scorecard-scans', ['images' => [UploadedFile::fake()->create('card.pdf', 100, 'application/pdf')]])
            ->assertSessionHasErrors('images.0');

        $this->actingAs($editor)
            ->post('/scorecard-scans', ['images' => [UploadedFile::fake()->image('big.jpg')->size(13000)]])
            ->assertSessionHasErrors('images.0');

        $this->actingAs($editor)
            ->post('/scorecard-scans', [
                'images' => array_fill(0, 5, UploadedFile::fake()->image('card.jpg')),
            ])
            ->assertSessionHasErrors('images');

        $this->assertSame(0, ScorecardScan::count());
    }

    public function test_editor_cannot_view_another_editors_scan(): void
    {
        $scan = $this->uploadAs($this->editor());
        $other = $this->editor();

        $this->actingAs($other)->get("/scorecard-scans/{$scan->id}")->assertForbidden();
        $this->actingAs($other)->get("/scorecard-scans/{$scan->id}/images/0")->assertForbidden();
    }

    public function test_admin_can_view_any_scan(): void
    {
        $scan = $this->uploadAs($this->editor());
        $admin = User::factory()->create(['plan' => 'free', 'role' => 'admin']);

        $this->actingAs($admin)
            ->get("/scorecard-scans/{$scan->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ScorecardScan')
                ->where('scan.id', $scan->id)
                ->where('scan.status', ScorecardScan::STATUS_PENDING)
                ->has('scan.images', 1));
    }

    public function test_stored_image_can_be_streamed_back_to_the_owner(): void
    {
        $editor = $this->editor();
        $scan = $this->uploadAs($editor);

        $this->actingAs($editor)
            ->get("/scorecard-scans/{$scan->id}/images/0")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($editor)
            ->get("/scorecard-scans/{$scan->id}/images/7")
            ->assertNotFound();
    }

    public function test_discarding_a_scan_removes_the_row_and_its_images(): void
    {
        $editor = $this->editor();
        $scan = $this->uploadAs($editor);
        $path = $scan->images[0]['path'];

        $this->actingAs($editor)->delete("/scorecard-scans/{$scan->id}")->assertRedirect();

        $this->assertSame(0, ScorecardScan::count());
        Storage::disk(ScorecardScan::DISK)->assertMissing($path);
    }

    private function uploadAs(User $user): ScorecardScan
    {
        $this->actingAs($user)->post('/scorecard-scans', [
            'images' => [UploadedFile::fake()->image('card.jpg', 900, 600)],
        ]);

        return ScorecardScan::query()->latest('id')->firstOrFail();
    }

    private function makeCourse(): Course
    {
        return Course::create([
            'course_name' => 'Test Course',
            'lat' => 37.0,
            'lng' => -86.0,
            'layout_data' => ['hole_count' => 18, 'teeboxes' => []],
        ]);
    }
}
