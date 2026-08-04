<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit entry: who changed a course, when, and a compact summary
 * of what changed. `course_name`/`user_name` are snapshots so the record stays
 * meaningful after the course or user is deleted.
 *
 * @property int $id
 * @property int|null $course_id
 * @property string $course_name
 * @property int|null $user_id
 * @property string|null $user_name
 * @property string $action
 * @property array<int,array<string,mixed>>|null $changes
 */
class CourseRevision extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
