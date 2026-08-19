<?php

namespace Tests\Support;

/**
 * Builds cards for tests from the committed Bolingbrook parse, so the per-hole
 * numbers stay real rather than invented.
 */
class ScorecardFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function eighteen(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../Fixtures/scorecards/bolingbrook.json'),
            true,
        );
    }

    /**
     * The front nine on its own, as a nine-hole card reads: stroke indexes
     * renumbered 1–9, no back nine, printed totals recomputed, and ratings in
     * the low 30s the way a real nine is rated (Willow Hill prints 33.6/113
     * from the Blues).
     *
     * @return array<string, mixed>
     */
    public static function nine(): array
    {
        $card = self::eighteen();

        $card['holes'] = array_values(array_filter(
            $card['holes'],
            fn ($hole) => ($hole['nine'] ?? null) === 'out',
        ));

        foreach (['men', 'women'] as $gender) {
            $order = array_map(fn ($h) => $h['handicap'][$gender] ?? null, $card['holes']);
            asort($order);

            $index = 1;
            foreach (array_keys($order) as $position) {
                if ($card['holes'][$position]['handicap'][$gender] !== null) {
                    $card['holes'][$position]['handicap'][$gender] = $index++;
                }
            }

            $card['par']['out'][$gender] = array_sum(array_map(
                fn ($h) => (int) $h['par'][$gender], $card['holes']
            ));
            $card['par']['in'][$gender] = null;
            $card['par']['total'][$gender] = $card['par']['out'][$gender];
        }

        // A nine rates around half an eighteen; these mirror a real card.
        $ratings = [[33.6, 34.6], [32.3, 33.4], [31.1, 32.1], [30.2, 31.0]];

        foreach ($card['tees'] as $i => $tee) {
            $out = 0;
            foreach ($card['holes'] as $hole) {
                foreach ($hole['yardages'] as $yardage) {
                    if ((int) $yardage['teeId'] === (int) $tee['id']) {
                        $out += (int) $yardage['yards'];
                    }
                }
            }

            $card['tees'][$i]['yardage'] = ['out' => $out, 'in' => null, 'total' => $out];
            $card['tees'][$i]['rating'] = [
                'men' => $ratings[$i][0] ?? 33.0,
                'women' => $ratings[$i][1] ?? 34.0,
            ];
        }

        return $card;
    }
}
