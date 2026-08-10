<?php

namespace Tests\Feature\Console;

use App\Models\Country;
use App\Support\AddressComponents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressComponentsTest extends TestCase
{
    use RefreshDatabase;

    private function parser(): AddressComponents
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA']);
        Country::create(['id' => 2, 'name' => 'Canada', 'iso2' => 'CA', 'iso3' => 'CAN']);
        Country::create(['id' => 3, 'name' => 'Puerto Rico', 'iso2' => 'PR', 'iso3' => 'PRI']);
        Country::create(['id' => 4, 'name' => 'Australia', 'iso2' => 'AU', 'iso3' => 'AUS']);

        return new AddressComponents;
    }

    public function test_it_parses_a_us_address(): void
    {
        $p = $this->parser()->parse('2348 Grandin Rd, Cincinnati, OH 45208, USA');

        $this->assertSame('US', $p['country_code']);
        $this->assertSame('OH', $p['state_code']);
        $this->assertSame(['Cincinnati'], $p['city_candidates']);
    }

    public function test_it_parses_a_canadian_postal_code(): void
    {
        $p = $this->parser()->parse('4706 54 St, Lloydminster, SK S9V 0S1, Canada');

        $this->assertSame('CA', $p['country_code']);
        $this->assertSame('SK', $p['state_code']);
        $this->assertSame(['Lloydminster'], $p['city_candidates']);
    }

    public function test_it_parses_a_three_letter_australian_code(): void
    {
        $p = $this->parser()->parse('123 Fairway Dr, Sydney, NSW 2000, Australia');

        $this->assertSame('AU', $p['country_code']);
        $this->assertSame('NSW', $p['state_code']);
    }

    public function test_it_resolves_the_country_when_there_is_no_subdivision_code(): void
    {
        $p = $this->parser()->parse('Joyuda, Cabo Rojo, Puerto Rico');

        $this->assertSame('PR', $p['country_code']);
        $this->assertNull($p['state_code']);
    }

    public function test_a_street_name_is_not_mistaken_for_a_state_code(): void
    {
        // "St Andrews Blvd" is two letters + a word — only the digit requirement
        // stops it reading as the subdivision code "ST".
        $p = $this->parser()->parse('500 St Andrews Blvd, Belpre, OH 45714, USA');

        $this->assertSame('OH', $p['state_code']);
        $this->assertSame(['Belpre'], $p['city_candidates']);
    }

    public function test_it_returns_nothing_useful_for_an_unstructured_address(): void
    {
        $p = $this->parser()->parse('Road 933');

        $this->assertNull($p['country_code']);
        $this->assertNull($p['state_code']);
        $this->assertSame([], $p['city_candidates']);
    }

    public function test_it_handles_null_and_empty(): void
    {
        $parser = $this->parser();

        foreach ([null, '', '   '] as $input) {
            $this->assertNull($parser->parse($input)['country_code']);
        }
    }
}
