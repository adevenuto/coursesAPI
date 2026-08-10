<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One scorecard upload and its parse.
 *
 * The uploaded images plus the model's verbatim response (`raw_parse`) are kept
 * as immutable evidence: nothing is written to a course until an editor confirms,
 * and the raw parse outlives the apply so a bad mapping can be re-derived without
 * paying for the call again.
 *
 * A null `course_id` means the scan is destined for a course that doesn't exist yet.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $course_id
 * @property string $status
 * @property array<int,array<string,mixed>> $images
 * @property string $content_hash
 * @property string|null $raw_parse
 * @property array<string,mixed>|null $verification
 * @property string|null $model
 * @property array<string,mixed>|null $usage
 * @property string|null $error
 * @property Carbon|null $applied_at
 * @property int|null $applied_revision_id
 */
class ScorecardScan extends Model
{
    protected $guarded = [];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARSING = 'parsing';

    public const STATUS_PARSED = 'parsed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DISCARDED = 'discarded';

    /** The disk every scorecard image is stored on. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'verification' => 'array',
            'usage' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(CourseRevision::class, 'applied_revision_id');
    }

    /**
     * The decoded parse, or null when the scan hasn't produced one.
     *
     * @return array<string,mixed>|null
     */
    public function parsed(): ?array
    {
        if ($this->raw_parse === null) {
            return null;
        }

        $decoded = json_decode($this->raw_parse, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Absolute paths to the stored images, in upload order.
     *
     * @return array<int,string>
     */
    public function imagePaths(): array
    {
        return array_map(
            fn (array $image) => Storage::disk(self::DISK)->path($image['path']),
            $this->images ?? [],
        );
    }

    /**
     * Whether this scan can still be applied to a course.
     */
    public function isApplyable(): bool
    {
        return $this->status === self::STATUS_PARSED && $this->applied_at === null;
    }

    /**
     * Delete the stored images. Called when a scan is discarded.
     */
    public function deleteImages(): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->storageDirectory());
    }

    public function storageDirectory(): string
    {
        return 'scorecards/'.$this->id;
    }
}
