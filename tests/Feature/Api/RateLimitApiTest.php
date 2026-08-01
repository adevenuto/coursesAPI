<?php

namespace Tests\Feature\Api;

use Laravel\Sanctum\Sanctum;

class RateLimitApiTest extends ApiTestCase
{
    public function test_rate_limit_headers_are_present(): void
    {
        Sanctum::actingAs($this->proUser);

        $this->getJson('/api/v1/courses')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit');
    }

    public function test_burst_limit_returns_429(): void
    {
        // Shrink the free burst so we can trip it deterministically.
        config(['api.plans.free.per_minute' => 3]);
        Sanctum::actingAs($this->freeUser);

        for ($i = 0; $i < 3; $i++) {
            $this->getJson('/api/v1/courses')->assertOk();
        }

        $this->getJson('/api/v1/courses')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
