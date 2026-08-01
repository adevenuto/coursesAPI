<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/settings/api-keys')->assertRedirect('/login');
    }

    public function test_page_renders_with_usage_and_tokens(): void
    {
        $user = User::factory()->create(['plan' => 'pro']);

        $this->actingAs($user)
            ->get('/settings/api-keys')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/ApiKeys')
                ->where('usage.limit', 10000)
                ->has('usage.today')
                ->has('tokens')
                ->where('maxKeys', 10),
            );
    }

    public function test_user_can_create_a_key_and_sees_plaintext_once(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->post('/settings/api-keys', ['name' => 'Production']);

        $res->assertRedirect(route('api-keys.index'));
        $res->assertSessionHas('created_token');
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('Production', $user->tokens()->first()->name);
    }

    public function test_create_requires_a_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/api-keys', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_user_can_revoke_a_key(): void
    {
        $user = User::factory()->create();
        $tokenId = $user->createToken('temp')->accessToken->id;

        $this->actingAs($user)
            ->delete("/settings/api-keys/{$tokenId}")
            ->assertRedirect(route('api-keys.index'));

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_max_keys_is_enforced(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 10; $i++) {
            $user->createToken("k{$i}");
        }

        $this->actingAs($user)
            ->post('/settings/api-keys', ['name' => 'one too many'])
            ->assertSessionHasErrors('name');

        $this->assertSame(10, $user->fresh()->tokens()->count());
    }
}
