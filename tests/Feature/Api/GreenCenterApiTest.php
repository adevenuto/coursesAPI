<?php

namespace Tests\Feature\Api;

use Laravel\Sanctum\Sanctum;

class GreenCenterApiTest extends ApiTestCase
{
    public function test_free_plan_is_forbidden_from_green_centers(): void
    {
        Sanctum::actingAs($this->freeUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}/green-centers")
            ->assertForbidden()
            ->assertJsonPath('message', 'Green-center data requires a Pro or Max plan.');
    }

    public function test_pro_plan_gets_green_centers(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}/green-centers")
            ->assertOk()
            ->assertJsonPath('data.course_id', $this->bgCourse->id)
            ->assertJsonPath('data.source', 'golftrax')
            ->assertJsonCount(2, 'data.holes')
            ->assertJsonPath('data.holes.0.hole', 1);
    }

    public function test_green_centers_404_when_course_has_none(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson("/api/v1/courses/{$this->noGreenCourse->id}/green-centers")
            ->assertNotFound();
    }

    public function test_detail_hides_green_centers_for_free_but_flags_availability(): void
    {
        Sanctum::actingAs($this->freeUser);

        $res = $this->getJson("/api/v1/courses/{$this->bgCourse->id}")
            ->assertOk()
            ->assertJsonPath('data.green_centers_available', true);

        $this->assertArrayNotHasKey('green_centers', $res->json('data'));
    }

    public function test_detail_includes_green_centers_for_pro(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson("/api/v1/courses/{$this->bgCourse->id}")
            ->assertOk()
            ->assertJsonPath('data.green_centers.source', 'golftrax')
            ->assertJsonCount(2, 'data.green_centers.holes');
    }
}
