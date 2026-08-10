<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        // Browser key — sent to the client for the Maps JS API, so it must stay
        // HTTP-referer restricted. Google refuses referer-restricted keys on the
        // server-side web-service APIs, which is why geocoding needs its own.
        'places_key' => env('GOOGLE_MAPS_API_KEY'),

        // Server key — no referer restriction (IP-restrict it to the app host),
        // with the Geocoding API enabled. Falls back to the browser key only so
        // local experiments don't hard-fail on a missing env var.
        'geocoding_key' => env('GOOGLE_GEOCODING_API_KEY', env('GOOGLE_MAPS_API_KEY')),
    ],

    // Algolia public credentials for the browser (explorer autocomplete).
    // The search key is search-only — safe to expose. Admin indexing uses
    // Scout's ALGOLIA_SECRET (config/scout.php), never sent to the client.
    'algolia' => [
        'app_id' => env('ALGOLIA_APP_ID'),
        'search_key' => env('ALGOLIA_SEARCH_KEY'),
    ],

    // Scorecard parsing (editor-only). Server-side key — never sent to the
    // browser. Without it the scan feature reports a configuration error
    // rather than failing silently on every upload.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),

        // Where the vision call runs. Inline (the default) holds the HTTP
        // request for the 30-90s it takes, which is simple and needs no
        // infrastructure but is exposed to the server's request timeout.
        //
        // Set SCORECARD_INLINE_PARSE=false only if that timeout is actually
        // cutting parses off, and only once a cron-driven
        // `queue:work --stop-when-empty` is confirmed to be draining the queue.
        // Queued is slower for a single card (it adds a cron interval before the
        // parse even starts), so it's a remedy rather than an upgrade. Defaults
        // to inline so deploying the code before any cron exists can't leave
        // every parse hanging.
        'inline_parse' => (bool) env('SCORECARD_INLINE_PARSE', true),
    ],

];
