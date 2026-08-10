<?php

namespace Tests\Unit\Scorecard;

use App\Support\CourseLayoutWriter;
use App\Support\Scorecard\ScorecardMapper;
use PHPUnit\Framework\TestCase;

class ScorecardMapperTest extends TestCase
{
    private ScorecardMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ScorecardMapper;
    }

    /**
     * @return array<string, mixed>
     */
    private function card(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/scorecards/bolingbrook.json'),
            true,
        );
    }

    /**
     * @param  array<int, array{label: string, detail: string}>  $unmapped
     * @return array<int, string>
     */
    private function labels(array $unmapped): array
    {
        return array_column($unmapped, 'label');
    }

    public function test_course_scalars_come_across(): void
    {
        $mapped = $this->mapper->map($this->card());

        $this->assertSame('Bolingbrook Golf Club', $mapped['course']['course_name']);
        $this->assertSame('2001 Rodeo Dr., Bolingbrook, IL 60490', $mapped['course']['address']);
        $this->assertSame('(630) 771-9400', $mapped['course']['phone']);
        $this->assertSame('www.bolingbrookgolfclub.com', $mapped['course']['website']);
        $this->assertSame(18, $mapped['hole_count']);
    }

    public function test_each_tee_maps_with_its_holes(): void
    {
        $mapped = $this->mapper->map($this->card());

        $this->assertCount(4, $mapped['teeboxes']);

        $black = $mapped['teeboxes'][0];
        $this->assertSame('Black', $black['name']);
        $this->assertSame('#2C2C2A', $black['color']);
        $this->assertSame(73.4, $black['courseRating']);
        $this->assertSame(136, $black['slope']);
        $this->assertCount(18, $black['holes']);

        $this->assertSame(
            ['hole' => 1, 'par' => 4, 'length' => 367, 'handicap' => 7, 'handicapWomen' => 5],
            $black['holes'][0],
        );
    }

    public function test_gendered_values_are_only_emitted_when_they_differ(): void
    {
        $mapped = $this->mapper->map($this->card());

        // Black is rated for men only.
        $this->assertNull($mapped['teeboxes'][0]['courseRatingWomen']);
        $this->assertNull($mapped['teeboxes'][0]['slopeWomen']);

        // White carries a genuinely different women's rating and slope.
        $this->assertSame(74.1, $mapped['teeboxes'][2]['courseRatingWomen']);
        $this->assertSame(132, $mapped['teeboxes'][2]['slopeWomen']);

        // Hole 3's stroke index is 17 for both, so no pair is written — storing
        // [17, 17] would imply the card distinguishes them when it doesn't.
        $this->assertNull($mapped['teeboxes'][0]['holes'][2]['handicapWomen']);
    }

    public function test_tee_varying_par_maps_natively(): void
    {
        $card = $this->card();
        // Hole 1 plays a par 5 from the forward tee only.
        $card['holes'][0]['par']['men'] = 4;
        $mapped = $this->mapper->map($card);

        $this->assertSame(4, $mapped['teeboxes'][0]['holes'][0]['par']);
        $this->assertSame(4, $mapped['teeboxes'][3]['holes'][0]['par']);
    }

    public function test_the_mapped_shape_is_what_the_layout_writer_accepts(): void
    {
        $mapped = $this->mapper->map($this->card());

        $layout = CourseLayoutWriter::merge(null, $mapped['teeboxes'], [], $mapped['hole_count']);

        $this->assertSame(18, $layout['hole_count']);
        $this->assertCount(4, $layout['teeboxes']);

        $black = $layout['teeboxes'][0];
        $this->assertSame('Black', $black['name']);
        $this->assertSame(136, $black['slope']);
        // Resynced from the hole lengths, and matching the card's printed total.
        $this->assertSame(6667, $black['totalYardage']);

        $holes = (array) $black['holes'];
        // par/length become strings; hole 1's stroke index differs by gender so
        // it lands as a [men, women] pair, while hole 3's (17 for both) stays scalar.
        $this->assertSame(['par' => '4', 'length' => '367', 'handicap' => [7, 5]], $holes['hole-1']);
        $this->assertSame(17, $holes['hole-3']['handicap']);

        // White's women's rating/slope land as [men, women] pairs.
        $white = $layout['teeboxes'][2];
        $this->assertSame([68.9, 74.1], $white['courseRating']);
        $this->assertSame([125, 132], $white['slope']);
    }

    public function test_vendor_keys_survive_a_scan_apply(): void
    {
        $mapped = $this->mapper->map($this->card());
        $existing = ['golftraxx' => ['zip' => '60490'], 'teeboxes' => [], 'hole_count' => 18];

        $layout = CourseLayoutWriter::merge($existing, $mapped['teeboxes'], [], $mapped['hole_count']);

        $this->assertSame(['zip' => '60490'], $layout['golftraxx']);
    }

    public function test_unstorable_fields_are_reported_rather_than_dropped_silently(): void
    {
        $card = $this->card();
        $card['units'] = 'metres';
        $card['cardId'] = 'BGC-4417';
        $card['holes'][0]['name'] = 'Burn';
        $card['holes'][0]['maxTime'] = '0:13';
        $card['holes'][1]['cartPathOnly'] = 'yes';

        $labels = $this->labels($this->mapper->map($card)['unmapped']);

        $this->assertContains('Units', $labels);
        $this->assertContains('Hole names', $labels);
        $this->assertContains('Pace of play', $labels);
        $this->assertContains('Cart path only', $labels);
        $this->assertContains('Card ID', $labels);
        $this->assertContains('Print date', $labels);
        // The fixture's women's par differs from the men's on several holes.
        $this->assertContains("Women's par", $labels);
    }

    public function test_a_card_with_no_extras_reports_almost_nothing(): void
    {
        $card = $this->card();
        $card['printDate'] = '';
        foreach ($card['holes'] as $i => $hole) {
            $card['holes'][$i]['par']['women'] = $hole['par']['men'];
        }
        $card['paceOfPlay'] = ['out' => '', 'in' => '', 'total' => ''];

        $labels = $this->labels($this->mapper->map($card)['unmapped']);

        // Only the standing note about recomputed totals.
        $this->assertSame(['Printed totals'], $labels);
    }

    public function test_malformed_tee_colours_are_normalised_or_dropped(): void
    {
        $card = $this->card();
        $card['tees'][0]['hex'] = '2c2c2a';   // missing #
        $card['tees'][1]['hex'] = '#ABC';     // shorthand
        $card['tees'][2]['hex'] = 'black';    // not a colour at all
        $card['tees'][3]['hex'] = '';         // not printed

        $teeboxes = $this->mapper->map($card)['teeboxes'];

        $this->assertSame('#2C2C2A', $teeboxes[0]['color']);
        $this->assertSame('#AABBCC', $teeboxes[1]['color']);
        // Dropped, not fatal — a colour is cosmetic and must never sink a tee.
        $this->assertNull($teeboxes[2]['color']);
        $this->assertNull($teeboxes[3]['color']);
    }

    public function test_a_hole_with_no_yardage_for_a_tee_maps_to_null_not_zero(): void
    {
        $card = $this->card();
        $card['holes'][0]['yardages'][0]['yards'] = null;

        $mapped = $this->mapper->map($card);

        $this->assertNull($mapped['teeboxes'][0]['holes'][0]['length']);
    }
}
