<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'geocode' => [
        'provider' => env('GEOCODE_PROVIDER', 'nominatim'),
        'base_url' => env('GEOCODE_BASE_URL', 'https://nominatim.openstreetmap.org'),
        'user_agent' => env('GEOCODE_USER_AGENT', 'PrimeClassy/1.0 (+'.env('APP_URL', 'https://primeclassy.com').')'),
        'timeout' => env('GEOCODE_TIMEOUT', 4),
        'connect_timeout' => env('GEOCODE_CONNECT_TIMEOUT', 2),
        // Shared file cache for this single-host layout; shared Redis/database on multiple hosts.
        'cache_store' => env('GEOCODE_CACHE_STORE', 'file'),
        'min_interval' => env('GEOCODE_MIN_INTERVAL', 1),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openroute' => [
        'key' => env('OPENROUTE_API_KEY'),
        'base_url' => env('OPENROUTE_BASE_URL', 'https://api.openrouteservice.org'),
        'profile' => env('OPENROUTE_PROFILE', 'driving-car'),
    ],

    // IMP-001: Google sign-in (consumers only). Endpoints are config so tests can fake them;
    // the secret is read from the environment only and never logged or persisted.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        // Where the browser lands after the callback (SPA origin). Empty = same origin.
        'frontend_url' => env('GOOGLE_FRONTEND_URL', ''),
    ],

];
