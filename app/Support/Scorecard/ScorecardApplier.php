<?php

namespace App\Support\Scorecard;

use App\Models\Course;
use App\Models\ScorecardScan;
use App\Models\User;
use App\Support\CourseWriter;
use RuntimeException;

/**
 * Writes the sections of a scan an editor accepted.
 *
 * Everything not accepted is preserved exactly as it was: rejecting a tee leaves
 * that tee untouched rather than removing it, and a course keeps tees the card
 * never mentioned. The payload is rebuilt from the course's current state and
 * then overlaid, so CourseWriter still receives a complete attribute set and
 * behaves identically to a manual save.
 *
 * Out-of-range values are dropped rather than written. The verifier has already
 * reported them to the editor; writing a par of 8 would produce layout_data the
 * editor's own save path would then refuse, which is a worse failure than a gap.
 */
class ScorecardApplier
{
    public function __construct(
        private readonly CourseWriter $writer,
        private readonly ScorecardMapper $mapper,
    ) {}

    /**
     * @param  array<int, string>  $sections  accepted section keys
     */
    public function apply(ScorecardScan $scan, array $sections, User $editor): Course
    {
        $parse = $scan->parsed();

        if ($parse === null) {
            throw new RuntimeException('This scan has no parse to apply.');
        }

        $mapped = $this->mapper->map($parse);
        $course = $scan->course ?? new Course;
        $before = $course->exists ? $course->forEditor() : [];

        $accepted = fn (string $key) => in_array($key, $sections, true);

        $details = $accepted('details');
        $name = $details ? ($mapped['course']['course_name'] ?? null) : null;
        $name ??= $before['course_name'] ?? null;

        if ($name === null || trim((string) $name) === '') {
            throw new RuntimeException(
                'This card has no course name, so a new course cannot be created from it. '
                .'Create the course first, then apply the scan to it.'
            );
        }

        $attributes = [
            'course_name' => $name,
            'club_name' => $before['club_name'] ?? null,
            'address' => $this->pick($details, $mapped['course']['address'], $before['address'] ?? null),
            'postal_code' => $before['postal_code'] ?? null,
            'phone' => $this->pick($details, $mapped['course']['phone'], $before['phone'] ?? null),
            'website' => $this->pick($details, $mapped['course']['website'], $before['website'] ?? null),
            // A scorecard carries no coordinates. An existing course keeps its
            // own; a new one is placed by the editor afterwards.
            'lat' => $before['lat'] ?? null,
            'lng' => $before['lng'] ?? null,
            'hole_count' => $accepted('layout')
                ? $mapped['hole_count']
                : ($before['hole_count'] ?? null),
            'teeboxes' => $this->mergeTeeboxes($before['teeboxes'] ?? [], $mapped['teeboxes'], $sections),
            'green_centers' => $before['green_centers'] ?? [],
        ];

        return $this->writer->write($course, $attributes, $editor);
    }

    private function pick(bool $accepted, mixed $incoming, mixed $current): mixed
    {
        return $accepted && $incoming !== null ? $incoming : $current;
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $scanned
     * @param  array<int, string>  $sections
     * @return array<int, array<string, mixed>>
     */
    private function mergeTeeboxes(array $existing, array $scanned, array $sections): array
    {
        $result = $existing;

        foreach ($scanned as $i => $tee) {
            if (! in_array("tee:{$i}", $sections, true)) {
                continue; // rejected — leave whatever the course already had
            }

            $tee = $this->storable($tee);
            $match = ScorecardDiff::matchTee($result, (string) $tee['name']);

            if ($match === null) {
                $result[] = $tee;

                continue;
            }

            // Merge rather than replace so a field the card didn't print
            // (an unrated tee's slope, say) isn't blanked by accepting the tee.
            $result[$match] = $this->mergeTee($result[$match], $tee);
        }

        return array_values($result);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeTee(array $current, array $incoming): array
    {
        $merged = $incoming;

        foreach (['color', 'secondaryColor', 'courseRating', 'courseRatingWomen', 'slope', 'slopeWomen'] as $key) {
            $merged[$key] = $incoming[$key] ?? ($current[$key] ?? null);
        }

        $currentHoles = [];
        foreach ($current['holes'] ?? [] as $hole) {
            $currentHoles[(int) $hole['hole']] = $hole;
        }

        $merged['holes'] = array_map(function (array $hole) use ($currentHoles) {
            $was = $currentHoles[(int) $hole['hole']] ?? [];

            foreach (['par', 'length', 'handicap', 'handicapWomen'] as $key) {
                $hole[$key] = $hole[$key] ?? ($was[$key] ?? null);
            }

            return $hole;
        }, $incoming['holes']);

        return $merged;
    }

    /**
     * Null anything outside the ranges CourseValidationRules would accept, so a
     * misread digit leaves a gap instead of unstorable layout_data.
     *
     * @param  array<string, mixed>  $tee
     * @return array<string, mixed>
     */
    private function storable(array $tee): array
    {
        $tee['courseRating'] = self::within($tee['courseRating'], 55, 80);
        $tee['courseRatingWomen'] = self::within($tee['courseRatingWomen'], 55, 80);
        $tee['slope'] = self::within($tee['slope'], 55, 155);
        $tee['slopeWomen'] = self::within($tee['slopeWomen'], 55, 155);

        $tee['holes'] = array_map(function (array $hole) {
            $hole['par'] = self::within($hole['par'], 3, 6);
            $hole['length'] = self::within($hole['length'], 30, 900);
            $hole['handicap'] = self::within($hole['handicap'], 1, 36);
            $hole['handicapWomen'] = self::within($hole['handicapWomen'], 1, 36);

            return $hole;
        }, $tee['holes']);

        return $tee;
    }

    private static function within(mixed $value, float $min, float $max): mixed
    {
        if ($value === null) {
            return null;
        }

        return ((float) $value >= $min && (float) $value <= $max) ? $value : null;
    }
}
