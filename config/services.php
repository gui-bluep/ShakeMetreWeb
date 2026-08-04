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

    /*
    | TEMPORAIRE — À SUPPRIMER À LA FIN DE LA MIGRATION.
    |
    | L'ancienne application ShakeMetre, hébergée sur serveur FileMaker, lue pendant
    | le développement pour établir ce que la version web doit reproduire : le corps
    | des scripts n'est pas la seule chose que l'export ne dit pas, et une copie de
    | fichier posée sur un disque dérive de la réalité.
    |
    | Ce n'est PAS une dépendance de l'application. ShakeMetre devient purement web et
    | ses données vivent dans la base de ce projet ; rien dans app/ ne doit lire ce
    | bloc. Seuls des outils d'investigation jetables s'en servent, et il disparaît -
    | avec ses variables d'environnement - quand la migration est finie. C'est aussi
    | pourquoi ces clés ne sont pas dans .env.example : rien ne doit suggérer qu'une
    | installation en a besoin.
    |
    | À l'inverse, `shakedesign` ci-dessus est permanent : cette frontière-là reste.
    |
    | Le compte doit porter le privilège étendu « Accès via FileMaker Data API
    | (fmrest) ». Un compte en accès complet SANS ce privilège se fait refuser par
    | l'API avec une erreur 9, ce qui se lit comme un mauvais mot de passe.
    */
    'shakemetre_filemaker' => [
        'host' => env('SHAKEMETRE_FM_HOST'),
        'database' => env('SHAKEMETRE_FM_DATABASE'),
        'username' => env('SHAKEMETRE_FM_USERNAME'),
        'password' => env('SHAKEMETRE_FM_PASSWORD'),

        'version' => env('SHAKEMETRE_FM_API_VERSION', 'vLatest'),

        'timeout' => env('SHAKEMETRE_FM_TIMEOUT', 30),
        'connect_timeout' => env('SHAKEMETRE_FM_CONNECT_TIMEOUT', 5),
        'verify' => env('SHAKEMETRE_FM_VERIFY_TLS', true),
    ],

];
