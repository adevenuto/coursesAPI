<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Mockery;
use Tests\TestCase;

class AccountDeletionBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_subscribed_user_cancels_the_stripe_subscription(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_test']);

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('active')->andReturnTrue();
        $subscription->shouldReceive('cancelNow')->once();

        // Preload the relation so the deleting hook cancels our mock instead
        // of hitting Stripe.
        $user->setRelation('subscriptions', collect([$subscription]));

        $user->delete();

        $this->assertModelMissing($user);
    }

    public function test_account_deletion_is_not_blocked_when_stripe_cancel_fails(): void
    {
        Log::spy();

        $user = User::factory()->create(['stripe_id' => 'cus_test']);

        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('active')->andReturnTrue();
        $subscription->shouldReceive('cancelNow')->andThrow(new \RuntimeException('stripe unavailable'));

        $user->setRelation('subscriptions', collect([$subscription]));

        $user->delete();

        // The account is still gone, and the failure was logged for follow-up.
        $this->assertModelMissing($user);
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'Failed to cancel Stripe subscription'))
            ->once();
    }

    public function test_local_subscription_records_and_api_tokens_are_removed(): void
    {
        $user = User::factory()->create(['stripe_id' => 'cus_test']);

        // An already-ended subscription: active() is false, so no Stripe call.
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_test',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'ends_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('subscription_items')->insert([
            'subscription_id' => $subscriptionId,
            'stripe_id' => 'si_test',
            'stripe_product' => 'prod_test',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->createToken('key');

        $this->assertSame(1, $user->tokens()->count());

        $user->delete();

        $this->assertDatabaseMissing('subscriptions', ['id' => $subscriptionId]);
        $this->assertDatabaseMissing('subscription_items', ['subscription_id' => $subscriptionId]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }
}
