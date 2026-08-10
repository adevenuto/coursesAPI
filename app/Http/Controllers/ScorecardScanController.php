<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScorecardScanRequest;
use App\Models\Course;
use App\Models\ScorecardScan;
use App\Support\Scorecard\ScorecardImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Editor-only scorecard scanning: upload a photo of a scorecard, have it parsed,
 * review a diff of what would change, then apply the parts you accept.
 *
 * Nothing here writes to a course. The upload and the parse are staged on
 * `scorecard_scans`; the apply path (added with the diff preview) is the only
 * place a course is touched, and it goes through the same writer the manual
 * editor uses so the audit trail is identical.
 *
 * Gated by EnsureCourseEditor, plus a per-scan ownership check — being an editor
 * shouldn't grant access to another editor's uploads.
 */
class ScorecardScanController extends Controller
{
    public function create(Request $request): Response
    {
        $courseId = $request->integer('course_id') ?: null;
        $course = $courseId !== null ? Course::find($courseId) : null;

        Head::title($course !== null ? 'Scan a scorecard' : 'Scan a new course');

        return Inertia::render('ScorecardScan', [
            'scan' => null,
            'course' => $course !== null ? [
                'id' => $course->id,
                'course_name' => $course->course_name,
                'club_name' => $course->club_name,
            ] : null,
            'maxImages' => 4,
            'maxImageMb' => 12,
        ]);
    }

    public function store(StoreScorecardScanRequest $request): RedirectResponse
    {
        ScorecardImage::assertGdAvailable();

        $scan = ScorecardScan::create([
            'user_id' => $request->user()->id,
            'course_id' => $request->input('course_id'),
            'status' => ScorecardScan::STATUS_PENDING,
            'images' => [],
            'content_hash' => '',
        ]);

        try {
            $images = $this->storeImages($scan, $request->file('images'));
        } catch (\Throwable $e) {
            // Nothing is staged yet, so drop the row rather than leave a husk.
            $scan->deleteImages();
            $scan->delete();

            throw ValidationException::withMessages([
                'images' => $e->getMessage(),
            ]);
        }

        $scan->update([
            'images' => $images,
            // Hash the normalised bytes, not the upload: two shots of the same
            // card that resize identically should reuse one parse.
            'content_hash' => hash('sha256', implode('|', array_column($images, 'sha256'))),
        ]);

        return to_route('scorecard-scans.show', $scan);
    }

    public function show(Request $request, ScorecardScan $scan): Response
    {
        $this->authorizeScan($request, $scan);

        $scan->load('course:id,course_name,club_name');

        Head::title('Scorecard scan');

        return Inertia::render('ScorecardScan', [
            'scan' => $this->present($scan),
            'course' => $scan->course !== null ? [
                'id' => $scan->course->id,
                'course_name' => $scan->course->course_name,
                'club_name' => $scan->course->club_name,
            ] : null,
            'maxImages' => 4,
            'maxImageMb' => 12,
        ]);
    }

    /**
     * Stream a stored image. The files live on the private disk, so they can't
     * be served from public/ — this is the only way the preview can show them.
     */
    public function image(Request $request, ScorecardScan $scan, int $index): StreamedResponse
    {
        $this->authorizeScan($request, $scan);

        $image = $scan->images[$index] ?? abort(404);
        $disk = Storage::disk(ScorecardScan::DISK);

        abort_unless($disk->exists($image['path']), 404);

        return $disk->response($image['path'], null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function destroy(Request $request, ScorecardScan $scan): RedirectResponse
    {
        $this->authorizeScan($request, $scan);

        $scan->deleteImages();
        $scan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Scan discarded.']);

        return to_route('explorer');
    }

    /**
     * Normalise + persist the uploads under the scan's own directory.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    private function storeImages(ScorecardScan $scan, array $files): array
    {
        $disk = Storage::disk(ScorecardScan::DISK);
        $images = [];

        foreach (array_values($files) as $i => $file) {
            $relative = $scan->storageDirectory().'/'.($i + 1).'.jpg';
            $meta = ScorecardImage::normalizeTo($file, $disk->path($relative));

            $images[] = [
                'path' => $relative,
                'original_name' => $file->getClientOriginalName(),
                'mime' => 'image/jpeg',
                'bytes' => $meta['bytes'],
                'width' => $meta['width'],
                'height' => $meta['height'],
                'sha256' => $meta['sha256'],
            ];
        }

        return $images;
    }

    /**
     * The uploader or an admin. EnsureCourseEditor already ran, so this is only
     * about not letting one editor open another's scan.
     */
    private function authorizeScan(Request $request, ScorecardScan $scan): void
    {
        $user = $request->user();

        abort_unless($user !== null && ($scan->user_id === $user->id || $user->isAdmin()), 403);
    }

    /**
     * The scan as the page needs it. `raw_parse` is deliberately not sent whole —
     * the preview renders the mapped diff, not the model's raw output.
     *
     * @return array<string, mixed>
     */
    private function present(ScorecardScan $scan): array
    {
        return [
            'id' => $scan->id,
            'status' => $scan->status,
            'course_id' => $scan->course_id,
            'error' => $scan->error,
            'verification' => $scan->verification,
            'applied_at' => $scan->applied_at?->toIso8601String(),
            'images' => array_map(fn (array $image, int $i) => [
                'index' => $i,
                'url' => route('scorecard-scans.image', ['scan' => $scan->id, 'index' => $i]),
                'original_name' => $image['original_name'],
                'width' => $image['width'],
                'height' => $image['height'],
                'bytes' => $image['bytes'],
            ], $scan->images, array_keys($scan->images)),
        ];
    }
}
