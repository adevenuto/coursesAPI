<?php

namespace Tests\Feature\Api;

use Laravel\Sanctum\Sanctum;

class CourseApiTest extends ApiTestCase
{
    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/courses')->assertUnauthorized();
    }

    public function test_it_lists_courses_paginated_with_clean_meta(): void
    {
        Sanctum::actingAs($this->freeUser);

        $res = $this->getJson('/api/v1/courses?per_page=2');

        $res->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'club', 'city', 'state', 'country', 'latitude', 'longitude']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        // Verbose Laravel pagination links[] array must be gone.
        $this->assertArrayNotHasKey('links', $res->json('meta'));
    }

    public function test_it_searches_by_name(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?q=bowling')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Bowling Green Country Club');
    }

    public function test_it_filters_by_country_iso2(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?country=US')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/courses?country=CA')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_near_me_returns_distance_sorted_within_radius(): void
    {
        Sanctum::actingAs($this->freeUser);

        $res = $this->getJson('/api/v1/courses?lat=37.0132&lng=-86.43378&radius=25')->assertOk();

        // Two KY courses within 25km; Pebble Beach (CA) excluded.
        $res->assertJsonPath('meta.total', 2);
        $this->assertSame('Bowling Green Country Club', $res->json('data.0.name'));
        $this->assertLessThan((float) $res->json('data.1.distance_km'), (float) $res->json('data.0.distance_km'));
        $this->assertArrayHasKey('distance_km', $res->json('data.0'));
    }

    public function test_per_page_is_capped(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses?per_page=999')
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('per_page');
    }

    public function test_show_returns_scorecard(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->bgCourse->id)
            ->assertJsonPath('data.location.country.iso2', 'US')
            ->assertJsonPath('data.scorecard.hole_count', 18)
            ->assertJsonPath('data.scorecard.teeboxes.0.name', 'Gold')
            ->assertJsonPath('data.scorecard.teeboxes.0.holes.0.par', 4);
    }

    public function test_show_404_for_missing_course(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson('/api/v1/courses/99999999')->assertNotFound();
    }
}
