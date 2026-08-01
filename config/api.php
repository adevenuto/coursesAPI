<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Plans (tiers)
    |--------------------------------------------------------------------------
    | Plan lives on the user account; API keys inherit it. `per_day` is the
    | daily request quota, `per_minute` a burst cap. `premium` unlocks the
    | green-center endpoints. Billing is manual for now (assign a paid plan
    | via the dashboard/tinker until Stripe lands).
    */
    'plans' => [
        'free' => [
            'label' => 'Free',
            'per_day' => 250,
            'per_minute' => 30,
            'premium' => false,
        ],
        'pro' => [
            'label' => 'Pro',
            'per_day' => 10_000,
            'per_minute' => 120,
            'premium' => true,
        ],
        'max' => [
            'label' => 'Max',
            'per_day' => 100_000,
            'per_minute' => 600,
            'premium' => true,
        ],
    ],

    'default_plan' => 'free',

    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],

    // Max radius (km) accepted by the near-me query.
    'max_radius_km' => 100,
];
