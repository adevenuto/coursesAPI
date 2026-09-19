<?php

namespace App\Support\Scorecard;

use App\Models\Course;
use App\Models\ScorecardScan;
use App\Models\User;
use App\Support\CourseRating;
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
    /**
     * @param  array<int, string>  $sections  diff sections the editor accepted
     * @param  array<int, string>  $overrides  range-check keys the editor approved
     */
    public function apply(ScorecardScan $scan, array $sections, User $editor, array $overrides = []): Course
    {
        $parse = $scan->parsed();

        if ($parse === null) {
            throw new RuntimeException('This scan has no parse to apply.');
        }

        $mapped = $this->mapper->map($parse);

        // The verifier keys overrides by the parse's tee id; mapped teeboxes are
        // positional. Resolve here rather than carrying the id on the mapped
        // shape — mapped teeboxes are written straight into layout_data, so an
        // extra key would leak into stored data and into every diff.
        $approved = self::approvedByTeeIndex($parse, $overrides);
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

        $teeboxes = $this->mergeTeeboxes($before['teeboxes'] ?? [], $mapped['teeboxes'], $sections, $approved);

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
                // Never below what the merged tees actually hold. The card's own
                // count describes the card — a nine-hole card applied to an
                // eighteen would otherwise leave the course claiming to be a nine
                // while still storing eighteen holes of yardage.
                ? max((int) ($mapped['hole_count'] ?? 0), self::holesCovered($teeboxes)) ?: null
                : ($before['hole_count'] ?? null),
            'teeboxes' => $teeboxes,
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
    private function mergeTeeboxes(array $existing, array $scanned, array $sections, array $approved = []): array
    {
        $result = $existing;

        foreach ($scanned as $i => $tee) {
            if (! in_array("tee:{$i}", $sections, true)) {
                continue; // rejected — leave whatever the course already had
            }

            $tee = $this->storable($tee, $approved[$i] ?? []);
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

        $incomingHoles = [];
        foreach ($incoming['holes'] as $hole) {
            $incomingHoles[(int) $hole['hole']] = $hole;
        }

        // Walk the union, not the card.
        //
        // A card covers what it covers: one nine of a facility that prints per-nine
        // cards is a nine-hole scan applied to an eighteen-hole course. Mapping over
        // the incoming holes alone made the result exactly as long as the scan, so
        // holes 10-18 were not merged, not blanked, simply absent — 54 holes of
        // yardage, par and stroke index dropped from a six-tee course in one apply,
        // with nothing in the preview to say so.
        //
        // A scan says what it saw. It does not get to assert what isn't there.
        $numbers = array_unique([...array_keys($incomingHoles), ...array_keys($currentHoles)]);
        sort($numbers);

        $holes = [];
        foreach ($numbers as $number) {
            $was = $currentHoles[$number] ?? [];
            $hole = $incomingHoles[$number] ?? $was;

            foreach (['par', 'length', 'handicap', 'handicapWomen'] as $key) {
                $hole[$key] = $hole[$key] ?? ($was[$key] ?? null);
            }

            $hole['hole'] = $number;
            $holes[] = $hole;
        }

        $merged['holes'] = $holes;

        return $merged;
    }

    /**
     * Null anything outside the ranges CourseValidationRules would accept, so a
     * misread digit leaves a gap instead of unstorable layout_data.
     *
     * @param  array<string, mixed>  $tee
     * @return array<string, mixed>
     */
    private function storable(array $tee, array $approved = []): array
    {
        // Bounded against this tee's own hole count: a nine's ratings sit far
        // below an eighteen's, and nulling them would drop correct figures.
        $minRating = CourseRating::min(CourseRating::playedHoles($tee['holes']));

        // An approved field keeps whatever the card said. Everything else is
        // clamped exactly as before, so approving a rating does not quietly
        // wave through a misread yardage on the same tee.
        $keep = fn (string $field) => in_array($field, $approved, true);

        if (! $keep('courseRating')) {
            $tee['courseRating'] = self::within($tee['courseRating'], $minRating, CourseRating::MAX);
        }
        if (! $keep('courseRatingWomen')) {
            $tee['courseRatingWomen'] = self::within($tee['courseRatingWomen'], $minRating, CourseRating::MAX);
        }
        if (! $keep('slope')) {
            $tee['slope'] = self::within($tee['slope'], 55, 155);
        }
        if (! $keep('slopeWomen')) {
            $tee['slopeWomen'] = self::within($tee['slopeWomen'], 55, 155);
        }

        $tee['holes'] = array_map(function (array $hole) use ($keep) {
            $number = (int) ($hole['hole'] ?? 0);

            if (! $keep("hole:{$number}:par")) {
                $hole['par'] = self::within($hole['par'], 3, 6);
            }
            if (! $keep("hole:{$number}:length")) {
                $hole['length'] = self::within($hole['length'], 30, 900);
            }

            // Stroke index is never range-flagged by the verifier, so there is
            // nothing for an editor to have approved here.
            $hole['handicap'] = self::within($hole['handicap'], 1, 36);
            $hole['handicapWomen'] = self::within($hole['handicapWomen'], 1, 36);

            return $hole;
        }, $tee['holes']);

        return $tee;
    }

    /**
     * The highest hole number any tee carries, which is what hole_count means.
     *
     * @param  array<int, array<string, mixed>>  $teeboxes
     */
    private static function holesCovered(array $teeboxes): int
    {
        $highest = 0;

        foreach ($teeboxes as $tee) {
            foreach ($tee['holes'] ?? [] as $hole) {
                $highest = max($highest, (int) ($hole['hole'] ?? 0));
            }
        }

        return $highest;
    }

    /**
     * Regroup the editor's approved keys by mapped teebox index.
     *
     * Keys arrive addressed the way the verifier writes them —
     * `tee:{id}:courseRating`, `tee:{id}:hole:{n}:length`, `hole:{n}:par` — where
     * the id is the parse's own tee ordinal. Mapped teeboxes are positional, so
     * the parse is the only thing that can relate the two.
     *
     * A bare `hole:{n}:par` has no tee in it (par is read once per card), so it
     * applies to every accepted tee.
     *
     * @param  array<string, mixed>  $parse
     * @param  array<int, string>  $overrides
     * @return array<int, array<int, string>> mapped tee index => approved fields
     */
    private static function approvedByTeeIndex(array $parse, array $overrides): array
    {
        if ($overrides === []) {
            return [];
        }

        $indexById = [];
        foreach (array_values($parse['tees'] ?? []) as $i => $tee) {
            $indexById[(int) ($tee['id'] ?? 0)] = $i;
        }

        $shared = [];
        $byIndex = [];

        foreach ($overrides as $key) {
            if (preg_match('/^tee:(\d+):(.+)$/', (string) $key, $m)) {
                $index = $indexById[(int) $m[1]] ?? null;
                if ($index !== null) {
                    $byIndex[$index][] = $m[2];
                }

                continue;
            }

            if (preg_match('/^hole:\d+:(?:par)$/', (string) $key)) {
                $shared[] = (string) $key;
            }
        }

        if ($shared !== []) {
            foreach ($indexById as $index) {
                $byIndex[$index] = array_merge($byIndex[$index] ?? [], $shared);
            }
        }

        return $byIndex;
    }

    private static function within(mixed $value, float $min, float $max): mixed
    {
        if ($value === null) {
            return null;
        }

        return ((float) $value >= $min && (float) $value <= $max) ? $value : null;
    }
}
