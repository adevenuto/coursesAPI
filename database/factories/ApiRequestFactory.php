<?php

namespace Database\Factories;

use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequest>
 */
class ApiRequestFactory extends Factory
{
    protected $model = ApiRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token_id' => null,
            'token_name' => null,
            'method' => 'GET',
            'endpoint' => 'api/v1/courses',
            'status' => 200,
            'duration_ms' => fake()->numberBetween(8, 120),
            'response_bytes' => fake()->numberBetween(200, 40000),
            'result_count' => fake()->numberBetween(0, 25),
            'ip' => '203.0.113.0',
            'user_agent' => 'curl/8.4.0',
            'client' => 'curl',
            'search_term' => null,
            'query' => null,
            'created_at' => now(),
        ];
    }

    public function throttled(): static
    {
        return $this->state(fn () => [
            'status' => 429,
            'result_count' => null,
            'response_bytes' => null,
        ]);
    }

    public function forEndpoint(string $endpoint): static
    {
        return $this->state(fn () => ['endpoint' => $endpoint]);
    }
}
