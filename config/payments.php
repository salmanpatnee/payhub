<?php

return [

    /*
    |--------------------------------------------------------------------------
    | New payment form defaults
    |--------------------------------------------------------------------------
    |
    | Values the New payment form starts with. Both are optional. A default
    | that does not exist, is inactive, or that the signed in user may not use
    | (for example an agent who is not assigned to that brand) is ignored.
    |
    | brand:   the brand slug (slugs match on every environment, ids do not).
    | account: "provider:id", the same value the account selector uses,
    |          for example "stripe:1". Agents are locked to their assigned
    |          account, so they never get an account default.
    |
    */

    'defaults' => [
        'brand' => env('PAYMENT_DEFAULT_BRAND'),
        'account' => env('PAYMENT_DEFAULT_ACCOUNT'),
    ],

];
