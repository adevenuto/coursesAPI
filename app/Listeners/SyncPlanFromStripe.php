<?php

namespace App\Listeners;

use App\Support\Plans;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Keeps `users.plan` in sync with the customer's Stripe subscription.
 *
 * The plan is derived from the subscription's price id (see config/api.php),
 * so Stripe remains the source of truth. Cashier maintains its own
 * subscriptions table; this listener only mirrors the tier onto the user
 * so the API's rate limits and premium gating follow the billing state.
 */
class SyncPlanFromStripe
{
    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;
        $type = $payload['type'] ?? null;

        match ($type) {
            'customer.subscription.created',
            'customer.subscription.updated' => $this->onSubscriptionChanged($payload),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($payload),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onSubscriptionChanged(array $payload): void
    {
        $subscription = $payload['data']['object'] ?? [];
        $user = $this->resolveUser($subscription['customer'] ?? null);

        if (! $user) {
            return;
        }

        // A subscription that is no longer active drops the user to free.
        $status = $subscription['status'] ?? null;
        if (in_array($status, ['canceled', 'incomplete_expired', 'unpaid'], true)) {
            $this->setPlan($user, Plans::default());

            return;
        }

        $this->setPlan($user, Plans::keyForStripePrice($this->priceId($subscription)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onSubscriptionDeleted(array $payload): void
    {
        $subscription = $payload['data']['object'] ?? [];

        if ($user = $this->resolveUser($subscription['customer'] ?? null)) {
            $this->setPlan($user, Plans::default());
        }
    }

    private function resolveUser(?string $stripeId)
    {
        return $stripeId ? Cashier::findBillable($stripeId) : null;
    }

    /**
     * Pull the price id off the first subscription item.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function priceId(array $subscription): ?string
    {
        return $subscription['items']['data'][0]['price']['id']
            ?? $subscription['plan']['id']
            ?? null;
    }

    private function setPlan($user, string $planKey): void
    {
        if ($user->plan !== $planKey) {
            $user->forceFill(['plan' => $planKey])->save();
        }
    }
}
