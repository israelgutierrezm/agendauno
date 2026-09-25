<?php

declare(strict_types=1);

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
    | Google Sign-In (SSO tenant-local). El `client_id` es el mismo del lado web
    | (Google Identity Services) y sirve para validar el `aud` del ID token.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
    ],

    /*
    | Notificaciones push (Firebase Cloud Messaging, API HTTP v1): ruta al JSON de la
    | cuenta de servicio del proyecto de Firebase de la app. Sin él no se envían push.
    | El proyecto se toma del JSON (o de FCM_PROJECT_ID si se indica).
    */
    'fcm' => [
        'credenciales' => env('FCM_CREDENTIALS'),
        'proyecto' => env('FCM_PROJECT_ID'),
    ],

];
