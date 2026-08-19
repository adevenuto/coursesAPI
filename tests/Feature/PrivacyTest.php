<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_is_public(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Privacy'));
    }

    public function test_it_states_the_retention_window_the_code_actually_enforces(): void
    {
        // The published commitment is read from the same config the prune command
        // uses, so the two can't drift apart.
        config(['api.analytics.retention_days' => 45, 'api.analytics.ip_mode' => 'anonymized']);

        $this->get('/privacy')
            ->assertInertia(fn (Assert $page) => $page
                ->where('retentionDays', 45)
                ->where('ipMode', 'anonymized'));
    }

    public function test_it_is_indexable_and_named(): void
    {
        // A privacy policy nobody can find is not a privacy policy. The footer
        // link itself is Vue-rendered so it can't be asserted from the Inertia
        // response; what's checkable here is that the route is named and public.
        $this->assertSame(url('/privacy'), route('privacy'));

        $this->get('/privacy')
            ->assertOk()
            ->assertDontSee('noindex', false);
    }
}
