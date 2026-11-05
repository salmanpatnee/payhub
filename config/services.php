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

    'whop' => [
        // Constants, not secrets. API key and webhook secret live encrypted per account
        // on whop_accounts. Hosts are placeholders until the step 0 sandbox check (spec 0009, AC-13/AC-14).
        'hosts' => [
            // api: REST base. checkout: host put in front of the path Whop returns as purchase_url.
            // script: Whop Elements CDN host, used for script-src and frame-src; connect: API origin for connect-src (both embed mode, CSP, from @whop/elements contentSecurityPolicy()). Sandbox has no Elements yet (docs.whop.com sandbox guide).
            'sandbox' => ['api' => 'https://sandbox-api.whop.com/api/v1', 'connect' => 'https://sandbox-api.whop.com', 'checkout' => 'https://sandbox.whop.com', 'script' => 'https://cdn.whop.com'],
            'production' => ['api' => 'https://api.whop.com/api/v1', 'connect' => 'https://api.whop.com', 'checkout' => 'https://whop.com', 'script' => 'https://cdn.whop.com'],
        ],
        'lock_seconds' => 60,
        'connect_timeout' => 10,
        'timeout' => 30,
    ],

    'kurv' => [
        // Constants, not secrets. The API key lives encrypted per account on kurv_accounts (spec 0010).
        'hosts' => [
            'sandbox' => 'https://api-sandbox.kurv.app',
            'production' => 'https://api.kurv.app',
        ],
        'lock_seconds' => 60,
        'connect_timeout' => 10,
        // Total timeout for the create call; lookups use lookup_timeout.
        'timeout' => 30,
        'lookup_timeout' => 10,
        'poll_lookup_seconds' => 10,
        'min_age_seconds' => 120,
        'lookup_gap_seconds' => 300,
        'sweep_hours' => 72,
    ],

    'viva' => [
        // 'demo' targets the demo-*.vivapayments.com hosts; 'production' targets the live hosts.
        // Per-account client id/secret/merchant id/api key live (encrypted where applicable)
        // on the viva_accounts table — no global credentials here.
        'environment' => env('VIVA_ENVIRONMENT', 'demo'),
    ],

    'mpgs' => [
        // One gateway host for every MPGS account (spec 0005); no trailing slash.
        // Per-account merchant id, integration password and webhook secret live on
        // the mpgs_accounts table (password and secret encrypted).
        'host' => env('MPGS_GATEWAY_HOST', 'https://ap-gateway.mastercard.com'),
        // Default API version pre-filled for new accounts; the sandbox spike picks the final one.
        'api_version' => (int) env('MPGS_API_VERSION', 100),
        // How long a `prepared` attempt may wait before the sweep expires it.
        'session_lifetime_minutes' => (int) env('MPGS_SESSION_LIFETIME_MINUTES', 30),
    ],

    'authorizenet' => [
        // Constants per environment, not secrets (spec 0007). Per-account API login id,
        // transaction key, signature key and public client key live on the authorizenet_accounts table.
        // `script` serves Accept.js, `connect` is the host Accept.js posts the card to.
        'hosts' => [
            'sandbox' => [
                'api' => 'https://apitest.authorize.net',
                'script' => 'https://jstest.authorize.net',
                'connect' => 'https://apitest.authorize.net',
            ],
            'production' => [
                'api' => 'https://api.authorize.net',
                'script' => 'https://js.authorize.net',
                'connect' => 'https://api2.authorize.net',
            ],
        ],
        // Gateway-side window that rejects an identical repeat charge (card, amount, invoice).
        'duplicate_window_seconds' => (int) env('AUTHORIZENET_DUPLICATE_WINDOW_SECONDS', 300),
        // How long new charges are refused after an unclear outcome while the lookup runs.
        'unclear_pause_seconds' => (int) env('AUTHORIZENET_UNCLEAR_PAUSE_SECONDS', 120),
    ],

    // Card attempt lock (MPGS and Authorize.net): this many failed attempts pause the pay page.
    'card_lock' => [
        'max_attempts' => (int) env('CARD_LOCK_MAX_ATTEMPTS', 3),
        'minutes' => (int) env('CARD_LOCK_MINUTES', 60),
    ],

];
