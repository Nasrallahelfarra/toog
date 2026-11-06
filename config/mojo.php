<?php

return [

    // Temporary dashboard action; disable both the button and its web endpoint.
    'demo_catalog_cleanup_enabled' => env('DEMO_CATALOG_CLEANUP_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    |
    | "gemini" calls the Gemini API. "fake" returns deterministic demo output
    | and placeholder images, so the platform works locally without a key.
    | When no GEMINI_API_KEY is set the platform falls back to "fake".
    |
    */

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),

        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'text_model' => env('GEMINI_TEXT_MODEL', 'gemini-3.8-flash'),
            'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-nano-banana-2.1'),
            'timeout' => (int) env('GEMINI_TIMEOUT', 120),
        ],

        // USD per 1M tokens, and per generated image. Used for the AI cost
        // dashboard; update when Google changes prices.
        'pricing' => [
            'gemini-4-argon' => ['input' => 2.00, 'output' => 10.00],
            'gemini-3.8-flash' => ['input' => 0.75, 'output' => 3.75],
            'gemini-3.6-flash' => ['input' => 0.75, 'output' => 3.75],
            'gemini-3.5-flash-lite' => ['input' => 0.30, 'output' => 2.50],
            'gemini-3.1-flash-lite' => ['input' => 0.25, 'output' => 1.50],
            'gemini-3.1-pro-preview' => ['input' => 2.00, 'output' => 12.00],
            'gemini-2.5-pro' => ['input' => 1.25, 'output' => 10.00],
            'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
            'gemini-nano-banana-2.1' => ['input' => 1.50, 'output' => 7.50, 'image' => 0.0336],
            'gemini-3.1-flash-image' => ['input' => 0.50, 'output' => 3.00, 'image' => 0.067],
            'gemini-3.1-flash-lite-image' => ['input' => 0.25, 'output' => 1.50, 'image' => 0.0336],
            'gemini-3-pro-image' => ['input' => 2.00, 'output' => 12.00, 'image' => 0.134],
            'fake' => ['input' => 0, 'output' => 0, 'image' => 0],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Zid integration (FR-1.1)
    |--------------------------------------------------------------------------
    */

    'zid' => [
        'client_id' => env('ZID_CLIENT_ID'),
        'client_secret' => env('ZID_CLIENT_SECRET'),
        'redirect_uri' => env('ZID_REDIRECT_URI', env('APP_URL').'/store/integrations/zid/callback'),
        'oauth_url' => env('ZID_OAUTH_URL', 'https://oauth.zid.sa'),
        'api_url' => env('ZID_API_URL', 'https://api.zid.sa/v1'),
        // Zid sends order times in UTC; sales months follow the store's clock.
        'timezone' => env('ZID_STORE_TIMEZONE', 'Asia/Riyadh'),
        // Revenue is kept in this currency when an order does not name the store's.
        'currency' => env('ZID_STORE_CURRENCY', 'SAR'),
        // Zid's exchange rates are per US dollar; the riyal uses its 3.75 peg when unset.
        'currency_per_usd' => env('ZID_STORE_CURRENCY_PER_USD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store defaults (FR-3.11, FR-5.2)
    |--------------------------------------------------------------------------
    |
    | New stores copy these values; the platform admin can change the
    | defaults and each store can override its own copy.
    |
    */

    'defaults' => [
        'accept_threshold' => 75,
        'reject_threshold' => 50,
        'proven_ratio' => 70,
        'sync_frequency' => 'daily',
        'weights' => [
            'success_similarity' => 25,
            'trend_fit' => 20,
            'saudi_fit' => 15,
            'failure_risk' => 15,
            'saturation' => 10,
            'price_fit' => 15,
        ],
    ],

];
