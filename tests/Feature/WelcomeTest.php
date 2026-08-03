<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_renders_with_plans_from_config(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->has('plans.free')
                ->has('plans.pro')
                ->has('plans.max')
                // Pricing block must read price + limits from config/api.php so
                // the marketing cards stay aligned with billing.
                ->where('plans.pro.price', config('api.plans.pro.price'))
                ->where('plans.pro.per_day', config('api.plans.pro.per_day'))
                ->where('plans.max.price', config('api.plans.max.price'))
                ->where('plans.free.price', config('api.plans.free.price')),
            );
    }
}
