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

    'revolut' => [
        // 'sandbox' targets sandbox-merchant.revolut.com; 'prod' targets merchant.revolut.com.
        // Per-account secret/webhook keys live (encrypted) on the revolut_accounts table.
        'environment' => env('REVOLUT_ENVIRONMENT', 'sandbox'),
        // Pinned Merchant API version (YYYY-MM-DD) sent on every request.
        'api_version' => env('REVOLUT_API_VERSION', '2024-09-01'),
    ],

    'viva' => [
        // 'demo' targets the demo-*.vivapayments.com hosts; 'production' targets the live hosts.
        // Per-account client id/secret/merchant id/api key live (encrypted where applicable)
        // on the viva_accounts table — no global credentials here.
        'environment' => env('VIVA_ENVIRONMENT', 'demo'),
    ],

];
