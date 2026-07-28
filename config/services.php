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

    /*
    | ShakeDesign stays a FileMaker application throughout this migration, so
    | ShakeMetre reads projects/companies/contacts/VAT values from it and writes
    | offers and supplier orders back to it over the FileMaker Data API.
    |
    | The layouts are dedicated API layouts, never the interface ones: a Data API
    | request only sees fields present on the layout it targets.
    */
    'shakedesign' => [
        'host' => env('SHAKEDESIGN_HOST'),
        'database' => env('SHAKEDESIGN_DATABASE'),
        'username' => env('SHAKEDESIGN_USERNAME'),
        'password' => env('SHAKEDESIGN_PASSWORD'),

        'version' => env('SHAKEDESIGN_API_VERSION', 'vLatest'),

        'timeout' => env('SHAKEDESIGN_TIMEOUT', 15),
        'connect_timeout' => env('SHAKEDESIGN_CONNECT_TIMEOUT', 5),
        'verify' => env('SHAKEDESIGN_VERIFY_TLS', true),

        /*
        | A Data API session dies after 15 minutes idle, so the token is cached
        | just under that. An early server-side expiry is still recovered from by
        | re-authenticating once and replaying the request.
        */
        'token_cache_key' => 'shakedesign:data-api:token',
        'token_ttl' => 840,
    ],

];
