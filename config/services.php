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

    // Which provider owns this site's Apple Pay domain verification file. Apple checks only one file
    // per domain, so each deployment picks one: 'square' (default) or 'revolut'. The file is served by
    // AppleDomainAssociationController from resources/apple-pay/{provider}, and only that provider's
    // pay page offers Apple Pay.
    'apple_pay' => [
        'provider' => env('APPLE_PAY_PROVIDER', 'square'),
    ],

    // Extra CSP hosts for one tap wallets, added only on the pay page of that provider's payment.
    // Square: Google Pay, Apple Pay and Cash App Pay (Web Payments SDK). Revolut: Google Pay and
    // Revolut Pay (Merchant Web SDK; most of it runs inside merchant.revolut.com frames already allowed).
    'wallet_hosts' => [
        'square' => [
            'script' => ['https://pay.google.com', 'https://applepay.cdn-apple.com', 'https://kit.cash.app'],
            'frame' => ['https://pay.google.com', 'https://*.cash.app', 'https://applepay.cdn-apple.com'],
            'connect' => ['https://pay.google.com', 'https://google.com', 'https://www.google.com', 'https://api.cash.app', 'https://*.cash.app', 'https://apple.com', 'https://www.apple.com', 'https://applepay.cdn-apple.com'],
            'font' => ['https://applepay.cdn-apple.com'],
        ],
        // NMI Google Pay (spec 0012, AC-16): the starting set; the browser run in step 6 confirms or widens it.
        'google_pay' => [
            'script' => ['https://pay.google.com'],
            'frame' => ['https://pay.google.com'],
            'connect' => ['https://pay.google.com', 'https://google.com', 'https://www.google.com'],
            'font' => [],
        ],
        'revolut' => [
            'script' => ['https://pay.google.com', 'https://applepay.cdn-apple.com'],
            // applepay.cdn-apple.com: the Apple Pay QR code window in non Safari browsers is a frame from this host.
            'frame' => ['https://pay.google.com', 'https://checkout.revolut.com', 'https://sandbox-checkout.revolut.com', 'https://applepay.cdn-apple.com'],
            // apple.com: Apple Pay's payment method id (https://apple.com/apple-pay) in non Safari browsers.
            'connect' => ['https://pay.google.com', 'https://google.com', 'https://www.google.com', 'https://apple.com', 'https://www.apple.com', 'https://applepay.cdn-apple.com'],
            'font' => ['https://applepay.cdn-apple.com'],
        ],
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
        // Extra hosts the embed needs for PayPal, Google Pay, Apple Pay and ACH bank linking (Plaid).
        // Copied from @whop/elements contentSecurityPolicy(); added to the CSP only on an embed pay page.
        'payment_method_hosts' => [
            'script' => [
                'https://pay.google.com', 'https://applepay.cdn-apple.com', 'https://js.braintreegateway.com',
                'https://www.paypal.com', 'https://c.paypal.com', 'https://cdn.plaid.com',
                'https://assets.braintreegateway.com', 'https://www.sandbox.paypal.com',
            ],
            'frame' => [
                'https://checkout.paypal.com', 'https://assets.braintreegateway.com',
                'https://www.paypal.com', 'https://cdn.plaid.com',
                'https://www.sandbox.paypal.com', 'https://checkout.sandbox.paypal.com',
            ],
            'font' => ['https://applepay.cdn-apple.com'],
            'connect' => [
                'https://pay.google.com', 'https://google.com', 'https://account.google.com', 'https://www.google.com',
                'https://api.basistheory.com', 'https://payments.braintree-api.com', 'https://api.braintreegateway.com',
                'https://api-m.paypal.com', 'https://www.paypal.com', 'https://production.plaid.com',
                'https://api-m.sandbox.paypal.com', 'https://www.sandbox.paypal.com',
                'https://payments.sandbox.braintree-api.com', 'https://api.sandbox.braintreegateway.com', 'https://sandbox.plaid.com',
            ],
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

    'nmi' => [
        // Constants per environment, not secrets (spec 0012). Per-account private key, public tokenization key
        // and webhook signing key live on the nmi_accounts table. A white label host is only an env change.
        'hosts' => [
            'sandbox' => env('NMI_SANDBOX_HOST', 'https://sandbox.nmi.com'),
            'production' => env('NMI_PRODUCTION_HOST', 'https://secure.nmi.com'),
        ],
        'connect_timeout' => 10,
        'timeout' => 30,
        'lookup_timeout' => 10,
        // How long new charges are refused after an unclear outcome while the lookup runs.
        'unclear_pause_seconds' => (int) env('NMI_UNCLEAR_PAUSE_SECONDS', 120),
        'poll_lookup_seconds' => 5,
        // The sweep looks back this far and stops after this many minutes with no transaction found.
        'sweep_hours' => 48,
        'give_up_minutes' => 30,
        // 3DS challenge frame hosts for the CSP: confirmed in the browser run (spec 0012, step 0).
        // Browser run 2026-11-14: the component loads Gateway.js from this host when 3DS is on.
        'three_ds_hosts' => ['https://secure.networkmerchants.com'],
    ],

    'worldpay' => [
        // Constants per environment, not secrets (spec 0014). Username, password, entity and the optional
        // webhook secret live encrypted on worldpay_accounts. Hosts and values below come from step 0.
        'hosts' => [
            'try' => 'https://try.access.worldpay.com',
            'live' => 'https://access.worldpay.com',
        ],
        // The hosted page may only be on one of these hosts (a bad reply must not become an open redirect).
        // Try returned both for identical requests. Live returned hpp.worldpay.com (seen 2026-11-19); payments.worldpay.com is kept as a guess.
        'hpp_hosts' => [
            'try' => ['payments-test.worldpay.com', 'hpp-sandbox.worldpay.com'],
            'live' => ['hpp.worldpay.com', 'payments.worldpay.com'],
        ],
        'connect_timeout' => 10,
        'timeout' => 30,
        'lookup_timeout' => 10,
        'checkout_lock_seconds' => 120,
        'lookup_lock_seconds' => 60,
        'resolver_deadline_seconds' => 45,
        // Worldpay accepts 300 to 2592000 seconds. 72 hours, so one page normally lasts the whole payment.
        'page_expiry_seconds' => 259200,
        'page_grace_seconds' => 120,
        // A page that returns the client on the failure or error address is closed and the next Pay now makes a new one
        // (Try, 2026-11-18: after that, the old page rejected every card). This caps how many pages one payment may use.
        'max_pages' => 3,
        // Set true if the staging run shows the client can retry on the same page after a refusal.
        // True since the Try test on 2026-11-18: after authorizationRefused the client paid on the same page.
        'reuse_after_decline' => true,
        'poll_lookup_seconds' => 10,
        'min_age_seconds' => 120,
        'lookup_gap_seconds' => 300,
        'sweep_hours' => 72,
        // Worldpay rejects more than 24 characters and anything outside a-z A-Z 0-9 - . , and space.
        // Billing address on the hosted page: HIDE, SHOW, EDIT, or empty to use the Worldpay account setting.
        // Hiding it may change what Worldpay uses for AVS checks, so check declines on Try before going live.
        'show_billing_address' => env('WORLDPAY_SHOW_BILLING_ADDRESS', 'HIDE'),
        'narrative_max' => 24,
        'narrative_pattern' => '/[^A-Za-z0-9\-., ]/',
        // lastEvent spellings. A Try payment with AUTHORISED (2026-11-18) ended on settlementRequestSubmitted after authorization.
        'paid_events' => ['sentForSettlement', 'settlementRequestSubmitted', 'settled'],
        'pending_events' => ['sentForAuthorization', 'authorized'],
        'declined_events' => ['authorizationFailed', 'authorizationRefused', 'refused', 'cancelled', 'cancelledByCustomer'],
        'closed_events' => ['expired', 'requestExpired'],
        'error_events' => ['error', 'settlementFailed'],
        'refund_events' => ['refunded', 'sentForRefund', 'chargedBack'],

        // Card fields mode (spec 0015). Hosts, session rules and 3DS names are NOT proven yet: they come from
        // build step 0 against Try, so each stays a config value until then.
        'card_max_refusals' => 5,
        'card_max_attempts' => 10,
        'card_pause_seconds' => 120,
        'card_lock_seconds' => 90,
        'card_http_timeout_seconds' => 30,
        'card_session_ttl_seconds' => 60,
        // A lookup row for the attempt always wins. No row after this many lookups over this many minutes lifts the block.
        'card_not_found_lookups' => 3,
        'card_not_found_minutes' => 10,
        'card_stuck_hours' => 24,
        // A final attempt (refused, abandoned, not_found) is looked up once, from the sweep, after this many hours.
        'card_final_lookup_after_hours' => 60,
        // Outcome names of the charge reply (step 0 confirms the spellings).
        'card_approved_events' => ['authorized', 'sentForSettlement'],
        'card_refused_events' => ['refused', 'fraudHighRisk'],
        'card_challenge_failed_events' => ['3dsAuthenticationFailed', '3dsUnavailable'],
        // Unproven (step 0): empty until Try answers. The card routes must stay off while these are empty.
        'checkout_hosts' => ['try' => [], 'live' => []],
        'session_hosts' => ['try' => [], 'live' => []],
        'session_path_prefix' => null,
        'three_ds_hosts' => [],
    ],

    // Card attempt lock (MPGS and Authorize.net): this many failed attempts pause the pay page.
    'card_lock' => [
        'max_attempts' => (int) env('CARD_LOCK_MAX_ATTEMPTS', 3),
        'minutes' => (int) env('CARD_LOCK_MINUTES', 60),
    ],

];
