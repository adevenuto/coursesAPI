<?php

namespace App\Support\Scorecard;

use App\Support\CourseRating;

/**
 * Re-checks a parsed scorecard's arithmetic in PHP.
 *
 * The model is asked to verify its own work, which helps, but a self-report is
 * not evidence — a card that doesn't add up is exactly the case where the model
 * is least reliable about saying so. Everything here is recomputed from the
 * per-hole values and compared against what the card printed.
 *
 * Two kinds of finding:
 *   - error   — the parse is wrong, or would be rejected by CourseValidationRules
 *               on apply. Blocks the default "apply everything" path.
 *   - warning — real-world oddity worth surfacing but not wrong: metres, a hole
 *               that plays longer from a shorter tee, a card missing stroke
 *               indexes entirely.
 *
 * Ranges mirror App\Concerns\CourseValidationRules so a scan can't stage a value
 * the editor's own save path would refuse.
 */
class ScorecardVerifier
{
    /**
     * @param  array<string, mixed>  $parse
     * @return array{passed: bool, issues: array<int, array{level: string, scope: string, message: string}>}
     */
    public function verify(array $parse): array
    {
        $issues = [];

        $tees = is_array($parse['tees'] ?? null) ? $parse['tees'] : [];
        $holes = is_array($parse['holes'] ?? null) ? $parse['holes'] : [];

        if ($tees === []) {
            $issues[] = self::error('tees', 'No tees were read from this card.');
        }
        if ($holes === []) {
            $issues[] = self::error('holes', 'No holes were read from this card.');
        }

        if ($tees === [] || $holes === []) {
            return self::result($issues);
        }

        array_push($issues, ...$this->checkHoleNumbering($holes));
        array_push($issues, ...$this->checkYardages($tees, $holes));
        array_push($issues, ...$this->checkPar($parse, $holes));
        array_push($issues, ...$this->checkHandicaps($holes));
        array_push($issues, ...$this->checkStoredRanges($tees, $holes));

        if (($parse['units'] ?? 'yards') === 'metres') {
            $issues[] = self::warning(
                'units',
                'This card is in metres. The numbers are stored as printed — nothing is converted to yards.'
            );
        }

        return self::result($issues);
    }

    /**
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array<string, string>>
     */
    private function checkHoleNumbering(array $holes): array
    {
        $numbers = array_map(fn ($h) => (int) ($h['number'] ?? 0), $holes);
        $expected = range(1, count($holes));

        if (array_values(array_unique($numbers)) !== $numbers) {
            return [self::error('holes', 'The same hole number appears more than once.')];
        }

        sort($numbers);
        if ($numbers !== $expected) {
            return [self::error(
                'holes',
                sprintf('Hole numbers are not a complete 1–%d run.', count($holes))
            )];
        }

        return [];
    }

    /**
     * Front nine sums to printed Out, back nine to In, both to Total — per tee.
     *
     * @param  array<int, array<string, mixed>>  $tees
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array<string, string>>
     */
    private function checkYardages(array $tees, array $holes): array
    {
        $issues = [];
        $teeIds = array_map(fn ($t) => (int) ($t['id'] ?? 0), $tees);

        foreach ($holes as $hole) {
            $number = (int) ($hole['number'] ?? 0);
            $present = array_map(fn ($y) => (int) ($y['teeId'] ?? 0), $hole['yardages'] ?? []);

            if (array_diff($teeIds, $present) !== []) {
                $issues[] = self::error(
                    "hole:{$number}",
                    sprintf('Hole %d is missing a yardage for one or more tees.', $number)
                );
            }
            if (array_diff($present, $teeIds) !== []) {
                $issues[] = self::error(
                    "hole:{$number}",
                    sprintf('Hole %d has a yardage for a tee that isn\'t on this card.', $number)
                );
            }
        }

        foreach ($tees as $tee) {
            $id = (int) ($tee['id'] ?? 0);
            $name = (string) ($tee['name'] ?? "tee {$id}");

            $out = $this->sumYards($holes, $id, 'out');
            $in = $this->sumYards($holes, $id, 'in');

            foreach ([['out', $out, 'front nine'], ['in', $in, 'back nine']] as [$key, $computed, $label]) {
                $printed = $tee['yardage'][$key] ?? null;

                if ($printed !== null && $computed !== null && (int) $printed !== $computed) {
                    $issues[] = self::error(
                        "tee:{$id}",
                        sprintf(
                            '%s: the %s yardages add up to %d but the card prints %d.',
                            $name, $label, $computed, (int) $printed
                        )
                    );
                }
            }

            $printedTotal = $tee['yardage']['total'] ?? null;
            $computedTotal = ($out ?? 0) + ($in ?? 0);

            if ($printedTotal !== null && $out !== null && $in !== null && (int) $printedTotal !== $computedTotal) {
                $issues[] = self::error(
                    "tee:{$id}",
                    sprintf(
                        '%s: the hole yardages add up to %d but the card prints a total of %d.',
                        $name, $computedTotal, (int) $printedTotal
                    )
                );
            }

            array_push($issues, ...$this->checkMonotonic($tees, $holes, $tee));
        }

        return $issues;
    }

    /**
     * A hole playing longer from a shorter tee is real — differing tee angles or
     * a shared teeing ground — so this is a note, not a rejection.
     *
     * @param  array<int, array<string, mixed>>  $tees
     * @param  array<int, array<string, mixed>>  $holes
     * @param  array<string, mixed>  $tee
     * @return array<int, array<string, string>>
     */
    private function checkMonotonic(array $tees, array $holes, array $tee): array
    {
        $index = array_search($tee, $tees, true);
        $next = $tees[$index + 1] ?? null;

        if ($next === null) {
            return [];
        }

        $longerId = (int) ($tee['id'] ?? 0);
        $shorterId = (int) ($next['id'] ?? 0);
        $flipped = [];

        foreach ($holes as $hole) {
            $a = $this->yardsFor($hole, $longerId);
            $b = $this->yardsFor($hole, $shorterId);

            if ($a !== null && $b !== null && $b > $a) {
                $flipped[] = (int) ($hole['number'] ?? 0);
            }
        }

        if ($flipped === []) {
            return [];
        }

        return [self::warning(
            'tee:'.$longerId,
            sprintf(
                'Hole %s plays longer from %s than from %s. This happens on real cards — check it, but it may be correct.',
                implode(', ', $flipped),
                (string) ($next['name'] ?? 'the shorter tee'),
                (string) ($tee['name'] ?? 'the longer tee'),
            )
        )];
    }

    /**
     * @param  array<string, mixed>  $parse
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array<string, string>>
     */
    private function checkPar(array $parse, array $holes): array
    {
        $issues = [];

        foreach (['men', 'women'] as $gender) {
            $out = $this->sumPar($holes, $gender, 'out');
            $in = $this->sumPar($holes, $gender, 'in');

            foreach ([['out', $out, 'front nine'], ['in', $in, 'back nine']] as [$key, $computed, $label]) {
                $printed = $parse['par'][$key][$gender] ?? null;

                if ($printed !== null && $computed !== null && (int) $printed !== $computed) {
                    $issues[] = self::error(
                        "par:{$gender}",
                        sprintf(
                            'The %s %s par adds up to %d but the card prints %d.',
                            $gender === 'men' ? "men's" : "women's", $label, $computed, (int) $printed
                        )
                    );
                }
            }

            $printedTotal = $parse['par']['total'][$gender] ?? null;

            if ($printedTotal !== null && $out !== null && $in !== null && (int) $printedTotal !== $out + $in) {
                $issues[] = self::error(
                    "par:{$gender}",
                    sprintf(
                        'The %s par adds up to %d but the card prints a total of %d.',
                        $gender === 'men' ? "men's" : "women's", $out + $in, (int) $printedTotal
                    )
                );
            }
        }

        return $issues;
    }

    /**
     * Each gender's stroke indexes are an independent sequence: no repeats, no gaps.
     *
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array<string, string>>
     */
    private function checkHandicaps(array $holes): array
    {
        $issues = [];
        $count = count($holes);

        foreach (['men', 'women'] as $gender) {
            $values = [];
            foreach ($holes as $hole) {
                $value = $hole['handicap'][$gender] ?? null;
                if ($value !== null) {
                    $values[] = (int) $value;
                }
            }

            $label = $gender === 'men' ? "men's" : "women's";

            if ($values === []) {
                $issues[] = self::warning(
                    "handicap:{$gender}",
                    "This card has no {$label} stroke indexes."
                );

                continue;
            }

            if (count($values) !== $count) {
                $issues[] = self::error(
                    "handicap:{$gender}",
                    sprintf('Only %d of %d holes have a %s stroke index.', count($values), $count, $label)
                );

                continue;
            }

            if (count(array_unique($values)) !== $count) {
                $duplicates = array_unique(array_diff_assoc($values, array_unique($values)));
                $issues[] = self::error(
                    "handicap:{$gender}",
                    sprintf('The %s stroke index %s appears more than once.', $label, implode(', ', $duplicates))
                );

                continue;
            }

            sort($values);

            // 18 holes must be exactly 1–18. A nine-hole card may legitimately
            // number 1–9 or take the odds/evens out of a parent 18.
            if ($count === 18 && $values !== range(1, 18)) {
                $issues[] = self::error(
                    "handicap:{$gender}",
                    sprintf('The %s stroke indexes are not a complete 1–18 run.', $label)
                );
            } elseif ($count === 9 && ($values[0] < 1 || end($values) > 18)) {
                $issues[] = self::error(
                    "handicap:{$gender}",
                    sprintf('The %s stroke indexes fall outside 1–18.', $label)
                );
            }
        }

        return $issues;
    }

    /**
     * Anything the editor's own save path would reject. Catching it here means
     * the preview explains the problem instead of the apply blowing up.
     *
     * @param  array<int, array<string, mixed>>  $tees
     * @param  array<int, array<string, mixed>>  $holes
     * @return array<int, array<string, string>>
     */
    private function checkStoredRanges(array $tees, array $holes): array
    {
        $issues = [];

        if (count($tees) > 12) {
            $issues[] = self::error('tees', sprintf(
                'This card has %d tees; a course can store at most 12.', count($tees)
            ));
        }

        // A nine rates about half of an eighteen, so the bound has to know how
        // long the card is or every correctly read nine-hole rating is an error.
        $minRating = CourseRating::min(count($holes));

        foreach ($tees as $tee) {
            $id = (int) ($tee['id'] ?? 0);
            $name = (string) ($tee['name'] ?? "tee {$id}");

            foreach (['men', 'women'] as $gender) {
                $rating = $tee['rating'][$gender] ?? null;
                if ($rating !== null && ($rating < $minRating || $rating > CourseRating::MAX)) {
                    $issues[] = self::error("tee:{$id}", sprintf(
                        '%s: a course rating of %s is outside the storable range (%s–%s).',
                        $name, $rating, $minRating, CourseRating::MAX
                    ));
                }

                $slope = $tee['slope'][$gender] ?? null;
                if ($slope !== null && ($slope < 55 || $slope > 155)) {
                    $issues[] = self::error("tee:{$id}", sprintf(
                        '%s: a slope of %s is outside the storable range (55–155).', $name, $slope
                    ));
                }
            }
        }

        foreach ($holes as $hole) {
            $number = (int) ($hole['number'] ?? 0);

            foreach (['men', 'women'] as $gender) {
                $par = $hole['par'][$gender] ?? null;
                if ($par !== null && ($par < 3 || $par > 6)) {
                    $issues[] = self::error("hole:{$number}", sprintf(
                        'Hole %d has a par of %s, outside the storable range (3–6).', $number, $par
                    ));
                }
            }

            foreach ($hole['yardages'] ?? [] as $yardage) {
                $yards = $yardage['yards'] ?? null;
                if ($yards !== null && ($yards < 30 || $yards > 900)) {
                    $issues[] = self::error("hole:{$number}", sprintf(
                        'Hole %d has a yardage of %s, outside the storable range (30–900).', $number, $yards
                    ));
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<int, array<string, mixed>>  $holes
     */
    private function sumYards(array $holes, int $teeId, string $nine): ?int
    {
        $sum = 0;
        $seen = false;

        foreach ($holes as $hole) {
            if (($hole['nine'] ?? null) !== $nine) {
                continue;
            }
            $yards = $this->yardsFor($hole, $teeId);
            if ($yards === null) {
                return null; // an incomplete nine can't be reconciled
            }
            $sum += $yards;
            $seen = true;
        }

        return $seen ? $sum : null;
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
     * @param  array<int, array<string, mixed>>  $holes
     */
    private function sumPar(array $holes, string $gender, string $nine): ?int
    {
        $sum = 0;
        $seen = false;

        foreach ($holes as $hole) {
            if (($hole['nine'] ?? null) !== $nine) {
                continue;
            }
            $par = $hole['par'][$gender] ?? null;
            if ($par === null) {
                return null;
            }
            $sum += (int) $par;
            $seen = true;
        }

        return $seen ? $sum : null;
    }

    /**
     * @param  array<int, array<string, string>>  $issues
     * @return array{passed: bool, issues: array<int, array{level: string, scope: string, message: string}>}
     */
    private static function result(array $issues): array
    {
        $hasError = array_filter($issues, fn ($i) => $i['level'] === 'error') !== [];

        return ['passed' => ! $hasError, 'issues' => array_values($issues)];
    }

    /**
     * @return array{level: string, scope: string, message: string}
     */
    private static function error(string $scope, string $message): array
    {
        return ['level' => 'error', 'scope' => $scope, 'message' => $message];
    }

    /**
     * @return array{level: string, scope: string, message: string}
     */
    private static function warning(string $scope, string $message): array
    {
        return ['level' => 'warning', 'scope' => $scope, 'message' => $message];
    }
}
