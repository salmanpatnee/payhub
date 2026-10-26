<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Central dashboard summary API (spec 0008)
    |--------------------------------------------------------------------------
    |
    | Tokens are never stored here, only their SHA 256 hashes. With no hash
    | configured the endpoint is off (404).
    |
    */

    'deployment_key' => env('CENTRAL_DEPLOYMENT_KEY'),

    'deployment_name' => env('CENTRAL_DEPLOYMENT_NAME'),

    'token_hash_current' => env('CENTRAL_API_TOKEN_HASH_CURRENT'),

    'token_hash_next' => env('CENTRAL_API_TOKEN_HASH_NEXT'),

    'timezone' => env('CENTRAL_TIMEZONE', 'Asia/Karachi'),

    'require_https' => env('CENTRAL_API_REQUIRE_HTTPS', env('APP_ENV') === 'production'),

    'cache_seconds' => (int) env('CENTRAL_API_CACHE_SECONDS', 60),

    'currencies' => ['usd', 'gbp', 'pkr'],

];
