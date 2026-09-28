<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor-submitted course suggestion. See the migration for why the location
 * is stored twice — resolved ids alongside the raw text — and why this row, not
 * the notification email, is the record.
 */
class CourseSuggestion extends Model
{
    /** A course or club we don't have yet. */
    public const TYPE_MISSING = 'missing';

    /** Something wrong with a course we do have; `course_id` says which. */
    public const TYPE_CORRECTION = 'correction';

    public const STATUS_NEW = 'new';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    /** Tripped the honeypot. Kept rather than dropped — see the controller. */
    public const STATUS_SPAM = 'spam';

    /** Accepted `type` values; the form select and validation both read this. */
    public const TYPES = [
        self::TYPE_MISSING,
        self::TYPE_CORRECTION,
    ];

    protected $fillable = [
        'type',
        'course_name',
        'club_name',
        'course_id',
        'country',
        'state',
        'city',
        'country_id',
        'state_id',
        'city_id',
        'website',
        'notes',
        'submitter_email',
        'user_id',
        'status',
        'ip',
        'user_agent',
        'mailed_at',
        'mail_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mailed_at' => 'datetime'];
    }

    public function isCorrection(): bool
    {
        return $this->type === self::TYPE_CORRECTION;
    }

    /** Human label for the notification email's subject line. */
    public function typeLabel(): string
    {
        return $this->isCorrection() ? 'Correction' : 'Missing course or club';
    }

    /**
     * Where the suggestion happened, as much of it as was given.
     *
     * City-first, deliberately the reverse of the form's country-first order:
     * the form reads top-down because that's the cascade, but prose reads
     * naturally the other way, and this matches every geo `label` the explorer
     * already produces (see City::toSearchableArray()).
     */
    public function locationLabel(): string
    {
        return collect([$this->city, $this->state, $this->country])
            ->filter()
            ->implode(', ');
    }

    /** Public course page for a correction, so the link works for any reader. */
    public function courseUrl(): ?string
    {
        if ($this->course_id === null || $this->course === null) {
            return null;
        }

        return url('/courses/'.$this->course_id.'/'.$this->course->urlSlug());
    }

    /**
     * What resolved to a real row and what still needs looking up — the line
     * that turns this table into a work queue.
     */
    public function matchSummary(): string
    {
        if ($this->isCorrection()) {
            return $this->course_id === null
                ? 'No course matched — the report names one we could not resolve.'
                : 'Matched course #'.$this->course_id.'.';
        }

        $matched = collect([
            'city' => $this->city_id,
            'state' => $this->state_id,
            'country' => $this->country_id,
        ])->filter(fn ($id) => $id !== null);

        if ($matched->isEmpty()) {
            return 'Nothing matched our geo data — needs research.';
        }

        $missing = collect(['city', 'state', 'country'])
            ->reject(fn (string $key) => $matched->has($key));

        $summary = 'Matched '.$matched->keys()->implode(', ').'.';

        return $missing->isEmpty()
            ? $summary
            : $summary.' Still to confirm: '.$missing->implode(', ').'.';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /*
     * Deliberately no country()/state()/city() relations. `country`, `state` and
     * `city` are string columns here, and Eloquent resolves an attribute before a
     * relation of the same name — so `$suggestion->city` would always be the text
     * and the relation would be unreachable by property access, which is a trap
     * rather than a feature. Nothing needs them: the text columns carry the names
     * for display, and the *_id columns exist to be looked up at research time.
     */
}
