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
    | products (e.g. "Fairway Pro"). `tax_code` is applied to every product —
    | Managed Payments requires one; default is SaaS, business use.
    */
    'stripe' => [
        'product_prefix' => env('STRIPE_PRODUCT_PREFIX', 'Fairway'),
        'tax_code' => env('STRIPE_TAX_CODE', 'txcd_10103001'),
    ],

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    // Max radius (km) accepted by the near-me query.
    'max_radius_km' => 100,
];
