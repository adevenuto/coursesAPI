<?php

namespace App\Support;

class Plans
{
    /**
     * All configured plans, keyed by plan key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return (array) config('api.plans', []);
    }

    /**
     * Paid plans only (those with a premium/priced tier), keyed by plan key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function paid(): array
    {
        return array_filter(self::all(), fn (array $plan) => (bool) ($plan['premium'] ?? false));
    }

    public static function default(): string
    {
        return (string) config('api.default_plan', 'free');
    }

    /**
     * Resolve a plan key from a Stripe price id. Returns the default plan
     * (free) when the price is unknown or null.
     */
    public static function keyForStripePrice(?string $priceId): string
    {
        if ($priceId === null || $priceId === '') {
            return self::default();
        }

        foreach (self::all() as $key => $plan) {
            if (($plan['stripe_price_id'] ?? null) === $priceId) {
                return (string) $key;
            }
        }

        return self::default();
    }

    /**
     * The Stripe price id for a given plan key, or null if unpriced/unknown.
     */
    public static function stripePriceFor(string $planKey): ?string
    {
        $id = self::all()[$planKey]['stripe_price_id'] ?? null;

        return $id !== null && $id !== '' ? (string) $id : null;
    }
}
