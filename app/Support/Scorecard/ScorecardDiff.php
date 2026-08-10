<?php

namespace App\Support\Scorecard;

use App\Models\Course;

/**
 * Builds the before/after the editor confirms against.
 *
 * CourseAuditor collapses every teebox change to the string "updated", which is
 * the right level of detail for an audit log and useless for deciding whether to
 * accept a parse. This produces the field- and hole-level comparison instead.
 *
 * Sections are the unit of acceptance. A tee is one section rather than four
 * (colour / rating / par / yardages) because the realistic failure is "this tee
 * was misread", not "this tee's slope specifically was misread" — and N+2
 * toggles stays comprehensible where 4N does not.
 *
 * Only sections that would actually change anything are returned, so an editor
 * re-applying a scan sees an empty diff rather than a page of no-ops.
 */
class ScorecardDiff
{
    /**
     * @param  array<string, mixed>  $mapped  ScorecardMapper output
     * @return array{
     *     sections: array<int, array<string, mixed>>,
     *     unmapped: array<int, array{label: string, detail: string}>,
     *     is_new: bool
     * }
     */
    public function build(?Course $course, array $mapped): array
    {
        $before = $course?->forEditor() ?? [];
        $sections = [];

        if ($details = $this->detailsSection($before, $mapped['course'])) {
            $sections[] = $details;
        }

        if ($layout = $this->holeCountSection($before, $mapped['hole_count'])) {
            $sections[] = $layout;
        }

        foreach ($mapped['teeboxes'] as $i => $tee) {
            $existingIndex = self::matchTee($before['teeboxes'] ?? [], $tee['name']);
            $existing = $existingIndex === null ? null : $before['teeboxes'][$existingIndex];

            if ($section = $this->teeSection($i, $tee, $existing)) {
                $sections[] = $section;
            }
        }

        return [
            'sections' => $sections,
            'unmapped' => $mapped['unmapped'],
            'is_new' => $course === null,
        ];
    }

    /**
     * Find an existing teebox for a scanned tee. Name is the only stable key —
     * positions shift as tees are added, and there are no tee ids in layout_data.
     *
     * @param  array<int, array<string, mixed>>  $teeboxes
     */
    public static function matchTee(array $teeboxes, string $name): ?int
    {
        $needle = self::normalize($name);

        if ($needle === '') {
            return null;
        }

        foreach ($teeboxes as $i => $teebox) {
            if (self::normalize((string) ($teebox['name'] ?? '')) === $needle) {
                return $i;
            }
        }

        return null;
    }

    private static function normalize(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim($name))) ?? '';
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, string|null>  $after
     * @return array<string, mixed>|null
     */
    private function detailsSection(array $before, array $after): ?array
    {
        $labels = [
            'course_name' => 'Name',
            'address' => 'Address',
            'phone' => 'Phone',
            'website' => 'Website',
        ];

        $fields = [];
        foreach ($labels as $key => $label) {
            // The card not printing something is not a reason to blank the course.
            if ($after[$key] === null) {
                continue;
            }
            $fields[] = $this->field($label, $before[$key] ?? null, $after[$key]);
        }

        return $this->section('details', 'Course details', 'details', $fields);
    }

    /**
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>|null
     */
    private function holeCountSection(array $before, int $after): ?array
    {
        if ($after < 1) {
            return null;
        }

        return $this->section('layout', 'Layout', 'details', [
            $this->field('Holes', $before['hole_count'] ?? null, $after),
        ]);
    }

    /**
     * @param  array<string, mixed>  $tee
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function teeSection(int $index, array $tee, ?array $existing): ?array
    {
        $fields = [
            $this->field('Colour', $existing['color'] ?? null, $tee['color']),
            $this->field('Rating (men)', $existing['courseRating'] ?? null, $tee['courseRating']),
            $this->field('Rating (women)', $existing['courseRatingWomen'] ?? null, $tee['courseRatingWomen']),
            $this->field('Slope (men)', $existing['slope'] ?? null, $tee['slope']),
            $this->field('Slope (women)', $existing['slopeWomen'] ?? null, $tee['slopeWomen']),
        ];

        $existingHoles = [];
        foreach ($existing['holes'] ?? [] as $hole) {
            $existingHoles[(int) $hole['hole']] = $hole;
        }

        $holes = [];
        foreach ($tee['holes'] as $hole) {
            $number = (int) $hole['hole'];
            $was = $existingHoles[$number] ?? [];

            $holes[] = [
                'hole' => $number,
                'cells' => [
                    'par' => $this->field('Par', $was['par'] ?? null, $hole['par']),
                    'length' => $this->field('Yards', $was['length'] ?? null, $hole['length']),
                    'handicap' => $this->field('SI', $was['handicap'] ?? null, $hole['handicap']),
                    'handicapWomen' => $this->field('SI (W)', $was['handicapWomen'] ?? null, $hole['handicapWomen']),
                ],
            ];
        }

        $section = $this->section(
            "tee:{$index}",
            (string) ($tee['name'] ?: 'Unnamed tee'),
            'tee',
            $fields,
            $holes,
        );

        if ($section !== null) {
            $section['status'] = $existing === null ? 'new' : 'update';
            $section['color'] = $tee['color'];
        }

        return $section;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<string, mixed>|null
     */
    private function section(string $key, string $label, string $kind, array $fields, array $holes = []): ?array
    {
        $states = array_column($fields, 'state');
        foreach ($holes as $hole) {
            $states = array_merge($states, array_column($hole['cells'], 'state'));
        }

        $counts = [
            'added' => count(array_keys($states, 'added', true)),
            'changed' => count(array_keys($states, 'changed', true)),
            'unchanged' => count(array_keys($states, 'unchanged', true)),
        ];

        // Nothing to decide about — leave it out rather than show a no-op row.
        if ($counts['added'] === 0 && $counts['changed'] === 0) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'status' => 'update',
            'counts' => $counts,
            'fields' => array_values(array_filter($fields, fn ($f) => $f['state'] !== 'skip')),
            'holes' => $holes,
        ];
    }

    /**
     * @return array{label: string, before: mixed, after: mixed, state: string}
     */
    private function field(string $label, mixed $before, mixed $after): array
    {
        $state = match (true) {
            $after === null || $after === '' => 'skip',   // the card didn't say
            $before === null || $before === '' => 'added',
            $this->same($before, $after) => 'unchanged',
            default => 'changed',
        };

        return ['label' => $label, 'before' => $before, 'after' => $after, 'state' => $state];
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.001;
        }

        return (string) $a === (string) $b;
    }
}
