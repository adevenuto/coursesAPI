<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plans (tiers)
    |--------------------------------------------------------------------------
    | Plan lives on the user account; API keys inherit it. `per_day` is the
    | daily request quota, `per_minute` a burst cap. `premium` unlocks the
    | green-center endpoints. `price` is the monthly USD amount shown in the
    | UI; `stripe_price_id` is the Stripe Price the subscription is billed on
    | (env-driven so test/live keys swap without code changes).
    */
    'plans' => [
        'free' => [
            'label' => 'Free',
            'per_day' => 30,
            'per_minute' => 30,
            'premium' => false,
            'price' => 0,
            'stripe_price_id' => null,
        ],
        'pro' => [
            'label' => 'Pro',
            'per_day' => 10_000,
            'per_minute' => 120,
            'premium' => true,
            'price' => 9.99,
            'stripe_price_id' => env('STRIPE_PRICE_PRO'),
        ],
        'max' => [
            'label' => 'Max',
            'per_day' => 100_000,
            'per_minute' => 600,
            'premium' => true,
            'price' => 19.99,
            'stripe_price_id' => env('STRIPE_PRICE_MAX'),
        ],
    ],

    'default_plan' => 'free',

    /*
    |--------------------------------------------------------------------------
    | Stripe / billing
    |--------------------------------------------------------------------------
    | Settings for `stripe:sync-products`. `product_prefix` names the Stripe
    | products (e.g. "GCA Pro") and derives the price lookup keys. `tax_code`
    | is applied to every product — Managed Payments requires one; default is
    | SaaS, business use.
    */
    'stripe' => [
        'product_prefix' => env('STRIPE_PRODUCT_PREFIX', 'GCA'),
        'tax_code' => env('STRIPE_TAX_CODE', 'txcd_10103001'),
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    // Max radius (km) accepted by the near-me query.
    'max_radius_km' => 100,

    /*
    |--------------------------------------------------------------------------
    | Usage analytics
    |--------------------------------------------------------------------------
    | `api_requests` is a rolling detail log — one row per API call, including
    | throttled ones. `api_usage` remains the forever, allowed-calls-only daily
    | rollup that billing reads, so the two will legitimately disagree.
    |
    | `ip_mode` decides how a client IP is stored. An IP is personal data under
    | GDPR whether or not we can identify the person, so the default anonymises:
    | IPv4 to /24, IPv6 to /48. That keeps distinct-network and rough-geography
    | signal while dropping the ability to single out a host. `full` is a
    | conscious opt-in; `hashed` looks stronger than it is (only ~4bn IPv4
    | inputs exist, so a hash is brute-forceable).
    */
    'analytics' => [
        'retention_days' => (int) env('API_ANALYTICS_RETENTION_DAYS', 90),
        'ip_mode' => env('API_ANALYTICS_IP_MODE', 'anonymized'), // anonymized|full|hashed
    ],

    /*
    |--------------------------------------------------------------------------
    | Nearby courses (editor)
    |--------------------------------------------------------------------------
    | The "Nearby courses" panel on the course editor. The cap matters more than
    | it looks: a 5-mile radius returns 2-3 courses for a typical course but
    | 24-30 around Pinehurst, Scottsdale and Myrtle Beach, which is exactly
    | where an editor works.
    */
    'nearby_radius_mi' => 5,
    'nearby_limit' => 12,
];
