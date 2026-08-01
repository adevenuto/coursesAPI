<?php

namespace Tests\Feature\Api;

use Laravel\Sanctum\Sanctum;

class GeoApiTest extends ApiTestCase
{
    public function test_countries_lookup(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/countries')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'iso2', 'iso3']]])
            ->assertJsonPath('data.0.iso2', 'US');
    }

    public function test_states_lookup_by_country(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/states?country=US')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Kentucky');

        $this->getJson('/api/v1/states')->assertStatus(422); // country required
    }

    public function test_cities_lookup_by_state(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/cities?state_prov_id=10')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'state_id', 'country_id']], 'meta'])
            ->assertJsonPath('data.0.name', 'Bowling Green');

        $this->getJson('/api/v1/cities')->assertStatus(422); // state_prov_id required
    }
}
