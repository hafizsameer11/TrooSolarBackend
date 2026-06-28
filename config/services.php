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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'mono' => [
        'public_key' => env('MONO_PUBLIC_KEY'),
        'secret_key' => env('MONO_SECRET_KEY'),
        'webhook_secret' => env('MONO_WEBHOOK_SECRET'),
        'env' => env('MONO_ENV', 'sandbox'),
        'run_credit_check' => env('MONO_RUN_CREDIT_CHECK', true),
        'mandate_default_address' => env(
            'MONO_MANDATE_DEFAULT_ADDRESS',
            '34 Adeola Odeku Street, Victoria Island, Lagos, Nigeria'
        ),
        'mandate_default_phone' => env('MONO_MANDATE_DEFAULT_PHONE', ''),
    ],

];
