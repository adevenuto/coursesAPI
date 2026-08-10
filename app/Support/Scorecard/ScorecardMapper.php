<?php

namespace App\Support\Scorecard;

/**
 * Translates a parsed scorecard into the shape App\Support\CourseLayoutWriter
 * accepts, and reports everything the card carried that `layout_data` has no
 * home for.
 *
 * The parse schema is deliberately richer than the stored shape. Rather than
 * quietly dropping the surplus, the mapper collects it so the preview can tell
 * the editor exactly what was read but won't be saved — the raw parse is kept on
 * the scan either way, so nothing is actually lost.
 *
 * Note the schema uses "" rather than null for absent text (unions are capped in
 * a structured-output schema), so emptiness is tested with self::text(), not a
 * null check.
 *
 * Gendered fields are only emitted as a pair when the women's value genuinely
 * differs. CourseLayoutWriter would happily store [16, 16], but that reads as
 * "this card distinguishes the two" when it doesn't.
 */
class ScorecardMapper
{
    /**
     * @param  array<string, mixed>  $parse
     * @return array{
     *     course: array<string, string|null>,
     *     hole_count: int,
     *     teeboxes: array<int, array<string, mixed>>,
     *     unmapped: array<int, array{label: string, detail: string}>
     * }
     */
    public function map(array $parse): array
    {
        $tees = is_array($parse['tees'] ?? null) ? $parse['tees'] : [];
        $holes = is_array($parse['holes'] ?? null) ? $parse['holes'] : [];

        return [
            'course' => [
                'course_name' => self::text($parse['name'] ?? null),
                'address' => self::text($parse['address'] ?? null),
                'phone' => self::text($parse['phone'] ?? null),
                'website' => self::text($parse['website'] ?? null),
            ],
            'hole_count' => count($holes),
            'teeboxes' => $this->teeboxes($tees, $holes),
            'unmapped' => $this->unmapped($parse, $holes),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $tees
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array<string, mixed>>
     */
    private function teeboxes(array $tees, array $holes): array
    {
        $teeboxes = [];

        foreach ($tees as $tee) {
            $id = (int) ($tee['id'] ?? 0);

            $teeHoles = [];
            foreach ($holes as $hole) {
                $entry = ['hole' => (int) ($hole['number'] ?? 0)];

                // Par lives per tee in layout_data, so a hole that plays a
                // different par from this tee maps natively.
                $entry['par'] = self::int($hole['par']['men'] ?? null);
                $entry['length'] = self::int($this->yardsFor($hole, $id));
                $entry['handicap'] = self::int($hole['handicap']['men'] ?? null);
                $entry['handicapWomen'] = self::differing(
                    $hole['handicap']['men'] ?? null,
                    $hole['handicap']['women'] ?? null,
                );

                $teeHoles[] = $entry;
            }

            $teeboxes[] = [
                'name' => (string) ($tee['name'] ?? ''),
                'color' => self::hex($tee['hex'] ?? null),
                'secondaryColor' => null,
                'courseRating' => self::float($tee['rating']['men'] ?? null),
                'courseRatingWomen' => self::differing(
                    $tee['rating']['men'] ?? null,
                    $tee['rating']['women'] ?? null,
                ),
                'slope' => self::int($tee['slope']['men'] ?? null),
                'slopeWomen' => self::differing(
                    $tee['slope']['men'] ?? null,
                    $tee['slope']['women'] ?? null,
                ),
                // The writer resyncs this from the hole lengths; it only matters
                // as a fallback for a totals-only card.
                'totalYardage' => self::int($tee['yardage']['total'] ?? null),
                'holes' => $teeHoles,
            ];
        }

        return $teeboxes;
    }

    /**
     * Everything read from the card that layout_data cannot hold.
     *
     * @param  array<string, mixed>  $parse
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array{label: string, detail: string}>
     */
    private function unmapped(array $parse, array $holes): array
    {
        $unmapped = [];

        if (($parse['units'] ?? 'yards') === 'metres') {
            $unmapped[] = self::note(
                'Units',
                'This card is in metres. Distances are stored exactly as printed — the API reports them as yards.'
            );
        }

        $named = array_filter($holes, fn ($h) => self::text($h['name'] ?? null) !== null);
        if ($named !== []) {
            $unmapped[] = self::note('Hole names', sprintf(
                '%d hole name%s read (e.g. “%s”). Courses have nowhere to store hole names.',
                count($named),
                count($named) === 1 ? '' : 's',
                (string) reset($named)['name'],
            ));
        }

        $pace = array_filter($parse['paceOfPlay'] ?? [], fn ($v) => self::text($v) !== null);
        $paced = array_filter($holes, fn ($h) => self::text($h['maxTime'] ?? null) !== null);
        if ($pace !== [] || $paced !== []) {
            $unmapped[] = self::note('Pace of play', trim(sprintf(
                '%s %s',
                $pace !== [] ? 'Printed pace-of-play times read.' : '',
                $paced !== [] ? sprintf('%d per-hole target time(s) read.', count($paced)) : '',
            )));
        }

        $cartPath = array_filter($holes, fn ($h) => ($h['cartPathOnly'] ?? null) === 'yes');
        if ($cartPath !== []) {
            $unmapped[] = self::note('Cart path only', sprintf(
                '%d hole(s) marked cart-path-only.', count($cartPath)
            ));
        }

        if ($this->hasGenderSplitPar($holes)) {
            $unmapped[] = self::note(
                "Women's par",
                "This card prints a separate women's par. Par is stored per tee rather than per gender, "
                ."so the men's par is what gets saved."
            );
        }

        foreach ([['cardId', 'Card ID'], ['printDate', 'Print date']] as [$key, $label]) {
            if (self::text($parse[$key] ?? null) !== null) {
                $unmapped[] = self::note($label, (string) $parse[$key]);
            }
        }

        $unmapped[] = self::note(
            'Printed totals',
            'Out/In/Total yardages are recalculated from the per-hole numbers on save, so the printed '
            .'values are kept on this scan rather than stored on the course.'
        );

        return $unmapped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $holes
     */
    private function hasGenderSplitPar(array $holes): bool
    {
        foreach ($holes as $hole) {
            $men = $hole['par']['men'] ?? null;
            $women = $hole['par']['women'] ?? null;

            if ($men !== null && $women !== null && (int) $men !== (int) $women) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $hole
     */
    private function yardsFor(array $hole, int $teeId): ?int
    {
        foreach ($hole['yardages'] ?? [] as $yardage) {
            if ((int) ($yardage['teeId'] ?? 0) === $teeId) {
                return $yardage['yards'] === null ? null : (int) $yardage['yards'];
            }
        }

        return null;
    }

    /**
     * The women's value, but only when it actually differs from the men's.
     */
    private static function differing(mixed $men, mixed $women): int|float|null
    {
        if ($women === null) {
            return null;
        }
        if ($men !== null && (float) $men === (float) $women) {
            return null;
        }

        return is_float($women) || is_float($men) ? (float) $women : (int) $women;
    }

    private static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function float(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    /**
     * Normalise to the #RRGGBB the course validation rules require, or drop it.
     * A colour is cosmetic — never fail an otherwise good tee over one.
     */
    private static function hex(mixed $value): ?string
    {
        $hex = ltrim(trim((string) ($value ?? '')), '#');

        if (preg_match('/^[0-9A-Fa-f]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return preg_match('/^[0-9A-Fa-f]{6}$/', $hex) ? '#'.strtoupper($hex) : null;
    }

    /**
     * @return array{label: string, detail: string}
     */
    private static function note(string $label, string $detail): array
    {
        return ['label' => $label, 'detail' => $detail];
    }
}
