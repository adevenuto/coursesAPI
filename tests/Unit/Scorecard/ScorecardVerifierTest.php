<?php

namespace Tests\Unit\Scorecard;

use App\Support\Scorecard\ScorecardVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScorecardFixture;

class ScorecardVerifierTest extends TestCase
{
    private ScorecardVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new ScorecardVerifier;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        return ScorecardFixture::eighteen();
    }

    /**
     * @param  array{passed: bool, issues: array<int, array<string, string>>}  $result
     * @return array<int, string>
     */
    private function messages(array $result, string $level): array
    {
        return array_values(array_map(
            fn ($i) => $i['message'],
            array_filter($result['issues'], fn ($i) => $i['level'] === $level),
        ));
    }

    public function test_a_card_that_reconciles_passes_cleanly(): void
    {
        $result = $this->verifier->verify($this->card());

        $this->assertTrue($result['passed']);
        $this->assertSame([], $this->messages($result, 'error'));
        $this->assertSame([], $this->messages($result, 'warning'));
    }

    public function test_a_bent_front_nine_total_is_caught_and_named(): void
    {
        $card = $this->card();
        $card['tees'][1]['yardage']['out'] += 7; // Blue

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $errors = $this->messages($result, 'error');
        // Only the nine is wrong: the printed total still matches the holes, so
        // the total check has nothing to say. Each printed figure is judged
        // against the holes, not against the other printed figures.
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Blue', $errors[0]);
        $this->assertStringContainsString('front nine', $errors[0]);
        $this->assertStringContainsString('3116', $errors[0]); // what the holes actually add to
    }

    public function test_a_bent_printed_total_is_caught_on_its_own(): void
    {
        $card = $this->card();
        $card['tees'][0]['yardage']['total'] += 25; // Black

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('prints a total of', $this->messages($result, 'error')[0]);
    }

    public function test_a_bent_par_total_is_caught_per_gender(): void
    {
        $card = $this->card();
        $card['par']['total']['women'] = 74; // real total is 76

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString("women's par", $this->messages($result, 'error')[0]);
    }

    public function test_a_duplicated_stroke_index_is_caught(): void
    {
        $card = $this->card();
        $card['holes'][5]['handicap']['men'] = $card['holes'][0]['handicap']['men'];

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('appears more than once', $this->messages($result, 'error')[0]);
    }

    public function test_a_gap_in_the_stroke_indexes_is_caught(): void
    {
        $card = $this->card();
        // Swap an 18 for a 19 — still unique, still 18 values, but not a 1–18 run.
        foreach ($card['holes'] as $i => $hole) {
            if ($hole['handicap']['men'] === 18) {
                $card['holes'][$i]['handicap']['men'] = 19;
            }
        }

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('complete 1–18 run', $this->messages($result, 'error')[0]);
    }

    public function test_missing_stroke_indexes_are_a_warning_not_an_error(): void
    {
        $card = $this->card();
        foreach ($card['holes'] as $i => $_) {
            $card['holes'][$i]['handicap']['women'] = null;
        }

        $result = $this->verifier->verify($card);

        // Plenty of real cards rate only one gender — that must not block a save.
        $this->assertTrue($result['passed']);
        $this->assertStringContainsString("no women's stroke indexes", $this->messages($result, 'warning')[0]);
    }

    public function test_a_partially_indexed_gender_is_an_error(): void
    {
        $card = $this->card();
        $card['holes'][3]['handicap']['men'] = null;

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Only 17 of 18 holes', $this->messages($result, 'error')[0]);
    }

    public function test_non_monotonic_yardage_is_reported_as_a_warning(): void
    {
        $card = $this->card();
        // Make hole 3 play longer from Blue than from Black, and fix the printed
        // totals so only the monotonicity finding remains.
        $delta = 40;
        $card['holes'][2]['yardages'][1]['yards'] += $delta;
        $card['tees'][1]['yardage']['out'] += $delta;
        $card['tees'][1]['yardage']['total'] += $delta;

        $result = $this->verifier->verify($card);

        $this->assertTrue($result['passed'], 'a real-world quirk must not block the save');
        $this->assertStringContainsString('plays longer from Blue', $this->messages($result, 'warning')[0]);
    }

    public function test_a_metric_card_is_flagged_but_not_converted(): void
    {
        $card = $this->card();
        $card['units'] = 'metres';

        $result = $this->verifier->verify($card);

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('metres', $this->messages($result, 'warning')[0]);
    }

    public function test_a_hole_missing_a_tee_yardage_is_caught(): void
    {
        $card = $this->card();
        array_pop($card['holes'][7]['yardages']);

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('missing a yardage', $this->messages($result, 'error')[0]);
    }

    public function test_values_the_course_could_not_store_are_errors(): void
    {
        $card = $this->card();
        $card['tees'][0]['slope']['men'] = 210;      // storable range is 55–155
        $card['holes'][0]['par']['men'] = 8;         // storable range is 3–6

        $result = $this->verifier->verify($card);

        $errors = implode(' | ', $this->messages($result, 'error'));

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('slope of 210', $errors);
        $this->assertStringContainsString('par of 8', $errors);
    }

    public function test_an_empty_parse_fails_without_blowing_up(): void
    {
        $result = $this->verifier->verify([]);

        $this->assertFalse($result['passed']);
        $this->assertCount(2, $this->messages($result, 'error'));
    }

    public function test_a_nine_hole_cards_ratings_are_not_flagged_as_unstorable(): void
    {
        // The reported case: Willow Hill prints 33.6 from the Blues, which a
        // flat 55-80 bound calls an error four times over.
        $result = $this->verifier->verify(ScorecardFixture::nine());

        $this->assertTrue($result['passed']);
        $this->assertSame([], $this->messages($result, 'error'));
    }

    public function test_the_same_ratings_on_an_eighteen_are_still_errors(): void
    {
        $card = $this->card();
        $card['tees'][0]['rating']['men'] = 33.6;

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString(
            'course rating of 33.6 is outside the storable range (55–80)',
            $this->messages($result, 'error')[0],
        );
    }

    public function test_a_nine_hole_card_still_rejects_a_misread_rating(): void
    {
        $card = ScorecardFixture::nine();
        $card['tees'][0]['rating']['men'] = 336; // the decimal point dropped

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('course rating of 336', $this->messages($result, 'error')[0]);
    }

    public function test_duplicate_hole_numbers_are_caught(): void
    {
        $card = $this->card();
        $card['holes'][4]['number'] = 4;

        $result = $this->verifier->verify($card);

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('more than once', $this->messages($result, 'error')[0]);
    }
}
