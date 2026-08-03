<?php

namespace Tests\Feature\Settings;

use App\Listeners\SyncPlanFromStripe;
use App\Models\User;
use App\Support\Plans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected(): void
    {
        $this->get('/settings/billing')->assertRedirect('/login');
    }

    public function test_page_renders_for_free_user(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->get('/settings/billing')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Billing')
                ->where('currentPlan', 'free')
                ->has('plans', 3)
                ->where('subscription', null)
                ->has('configured'),
            );
    }

    public function test_checkout_rejects_unknown_plan(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/billing/checkout', ['plan' => 'enterprise'])
            ->assertSessionHasErrors('plan');
    }

    public function test_checkout_rejects_plan_without_configured_price(): void
    {
        // No STRIPE_PRICE_* set in the test env, so 'pro' has no price id.
        config(['api.plans.pro.stripe_price_id' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/billing/checkout', ['plan' => 'pro'])
            ->assertSessionHasErrors('plan');
    }

    public function test_price_id_maps_back_to_plan_key(): void
    {
        config(['api.plans.pro.stripe_price_id' => 'price_pro_123']);

        $this->assertSame('pro', Plans::keyForStripePrice('price_pro_123'));
        $this->assertSame('free', Plans::keyForStripePrice('price_unknown'));
        $this->assertSame('free', Plans::keyForStripePrice(null));
    }

    public function test_webhook_promotes_user_to_paid_plan(): void
    {
        config(['api.plans.max.stripe_price_id' => 'price_max_123']);

        $user = User::factory()->create(['plan' => 'free']);
        $user->forceFill(['stripe_id' => 'cus_test_1'])->save();

        (new SyncPlanFromStripe)->handle(new WebhookReceived([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'customer' => 'cus_test_1',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_max_123']]]],
            ]],
        ]));

        $this->assertSame('max', $user->fresh()->plan);
    }

    public function test_webhook_downgrades_on_subscription_deleted(): void
    {
        $user = User::factory()->create(['plan' => 'pro']);
        $user->forceFill(['stripe_id' => 'cus_test_2'])->save();

        (new SyncPlanFromStripe)->handle(new WebhookReceived([
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['customer' => 'cus_test_2']],
        ]));

        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_webhook_downgrades_when_subscription_becomes_canceled(): void
    {
        config(['api.plans.pro.stripe_price_id' => 'price_pro_123']);

        $user = User::factory()->create(['plan' => 'pro']);
        $user->forceFill(['stripe_id' => 'cus_test_3'])->save();

        (new SyncPlanFromStripe)->handle(new WebhookReceived([
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'customer' => 'cus_test_3',
                'status' => 'canceled',
                'items' => ['data' => [['price' => ['id' => 'price_pro_123']]]],
            ]],
        ]));

        $this->assertSame('free', $user->fresh()->plan);
    }
}
