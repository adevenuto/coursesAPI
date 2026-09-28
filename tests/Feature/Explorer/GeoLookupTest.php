<?php

namespace Tests\Feature\Explorer;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public /geo/* typeaheads behind the suggestion modal's cascade.
 *
 * Requests use getJson throughout: these are web routes, so a validation
 * failure redirects rather than returning 422 unless the caller asks for JSON —
 * which the modal's fetch does.
 */
class GeoLookupTest extends TestCase
{
    use RefreshDatabase;

    private function seedGeo(): void
    {
        Country::create(['id' => 1, 'name' => 'United States', 'iso2' => 'US', 'iso3' => 'USA', 'latitude' => 38, 'longitude' => -97]);
        Country::create(['id' => 2, 'name' => 'Australia', 'iso2' => 'AU', 'iso3' => 'AUS', 'latitude' => -25, 'longitude' => 133]);

        $state = fn (int $id, string $name, int $country, string $iso2) => State::create([
            'id' => $id, 'name' => $name, 'country_id' => $country, 'country_code' => $country === 1 ? 'US' : 'AU',
            'country_name' => $country === 1 ? 'United States' : 'Australia', 'iso2' => $iso2,
            'latitude' => 0, 'longitude' => 0,
        ]);

        $state(10, 'Illinois', 1, 'IL');
        $state(11, 'California', 1, 'CA');
        $state(20, 'New South Wales', 2, 'NSW');

        $city = fn (int $id, string $name, int $stateId) => City::create([
            'id' => $id, 'name' => $name, 'state_id' => $stateId,
            'state_name' => 'Illinois', 'country_id' => $stateId === 20 ? 2 : 1,
            'country_code' => $stateId === 20 ? 'AU' : 'US',
            'country_name' => $stateId === 20 ? 'Australia' : 'United States',
            'latitude' => 41.0, 'longitude' => -88.0,
        ]);

        // Illinois. Springfield/New Springfield exercise prefix-vs-contains
        // ranking; Málaga exercises accent folding.
        $city(100, 'Springfield', 10);
        $city(101, 'New Springfield', 10);
        $city(102, 'Málaga', 10);
        $city(103, 'Lemont', 10);

        // Elsewhere, to prove scoping.
        $city(200, 'Monterey', 11);
        $city(300, 'Sydney', 20);
    }

    public function test_country_lookup_is_public_and_shaped_for_a_typeahead(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/countries')
            ->assertOk()
            ->assertJsonStructure(['results' => [['id', 'name', 'label', 'code']], 'truncated'])
            ->assertJsonPath('truncated', false);
    }

    public function test_countries_match_by_name_or_iso_code(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/countries?q=unit')->assertOk()->assertJsonPath('results.0.name', 'United States');

        // "us" is inside "Australia" too, so this pins the code tier ranking:
        // an exact ISO match has to outrank an alphabetically earlier substring.
        $this->getJson('/geo/countries?q=us')->assertOk()->assertJsonPath('results.0.name', 'United States');
    }

    public function test_states_require_a_country(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/states')->assertStatus(422)->assertJsonValidationErrorFor('country');
    }

    public function test_states_are_scoped_to_their_country(): void
    {
        $this->seedGeo();

        $names = collect($this->getJson('/geo/states?country=1')->assertOk()->json('results'))->pluck('name');

        $this->assertContains('Illinois', $names);
        $this->assertNotContains('New South Wales', $names);
    }

    public function test_cities_require_a_state(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/cities')->assertStatus(422)->assertJsonValidationErrorFor('state');
    }

    public function test_cities_are_scoped_to_their_state(): void
    {
        $this->seedGeo();

        $names = collect($this->getJson('/geo/cities?state=10')->assertOk()->json('results'))->pluck('name');

        $this->assertContains('Lemont', $names);
        $this->assertNotContains('Monterey', $names);
        $this->assertNotContains('Sydney', $names);
    }

    public function test_an_unknown_parent_returns_an_empty_list_not_a_404(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/cities?state=999999')->assertOk()->assertJsonPath('results', []);
        $this->getJson('/geo/states?country=999999')->assertOk()->assertJsonPath('results', []);
    }

    /**
     * The reason these endpoints exist at all. The Algolia geo indices are
     * restricted to course-bearing rows by shouldBeSearchable(), so a town with
     * no courses is invisible there — and that is exactly the town someone
     * reporting a missing course needs to name. If this test ever fails because
     * someone "simplified" this back onto Algolia, that is the bug.
     */
    public function test_lookups_include_places_with_no_courses(): void
    {
        $this->seedGeo();

        $this->assertSame(0, City::find(103)->courses()->count());

        $names = collect($this->getJson('/geo/cities?state=10&q=lemont')->assertOk()->json('results'))->pluck('name');

        $this->assertContains('Lemont', $names);
    }

    public function test_a_prefix_match_ranks_above_a_contains_match(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/cities?state=10&q=springfield')
            ->assertOk()
            ->assertJsonPath('results.0.name', 'Springfield');
    }

    public function test_results_are_capped_and_report_truncation(): void
    {
        $this->seedGeo();

        foreach (range(1, 25) as $i) {
            City::create([
                'id' => 1000 + $i, 'name' => 'Testville '.$i, 'state_id' => 10,
                'state_name' => 'Illinois', 'country_id' => 1, 'country_code' => 'US',
                'country_name' => 'United States', 'latitude' => 41.0, 'longitude' => -88.0,
            ]);
        }

        $res = $this->getJson('/geo/cities?state=10&q=testville')->assertOk();

        $this->assertCount(20, $res->json('results'));
        $this->assertTrue($res->json('truncated'));
    }

    /**
     * The one that matters most: an unescaped % turns a typeahead into a table
     * dump. Course::scopeSearch() has this bug today, so it is a live pattern in
     * this codebase, not a hypothetical.
     */
    public function test_like_wildcards_are_not_treated_as_wildcards(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/cities?state=10&q=%25')->assertOk()->assertJsonPath('results', []);
        $this->getJson('/geo/cities?state=10&q=_')->assertOk()->assertJsonPath('results', []);
        $this->getJson('/geo/countries?q=%25')->assertOk()->assertJsonPath('results', []);
    }

    public function test_an_oversized_query_is_rejected(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/countries?q='.str_repeat('a', 200))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('q');
    }

    public function test_lookups_are_throttled(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/countries')->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    }

    /**
     * Must be `private`: these run in the web group and so carry Set-Cookie,
     * which a shared cache must never store against.
     */
    public function test_lookups_are_not_publicly_cacheable(): void
    {
        $this->seedGeo();

        $header = $this->getJson('/geo/countries')->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', (string) $header);
        $this->assertStringNotContainsString('public', (string) $header);
    }

    /**
     * Asserts database collation behaviour (utf8mb4_0900_ai_ci), not app code —
     * safe because phpunit.xml pins DB_CONNECTION=mysql, but it would fail on
     * SQLite, which folds no accents.
     */
    public function test_accented_names_match_without_the_accent(): void
    {
        $this->seedGeo();

        $this->getJson('/geo/cities?state=10&q=malaga')
            ->assertOk()
            ->assertJsonPath('results.0.name', 'Málaga');
    }
}
