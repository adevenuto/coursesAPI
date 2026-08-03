<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_docs_are_public_and_carry_config(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docs')
                ->has('plans.free')
                ->has('plans.pro')
                ->has('baseUrl')
                ->where('pagination.max_per_page', 100),
            );
    }
}
