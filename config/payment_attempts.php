<?php

use App\Services\PaymentAttempts\Extractors\RevolutAttemptExtractor;
use App\Services\PaymentAttempts\Extractors\SquareAttemptExtractor;
use App\Services\PaymentAttempts\Extractors\StripeAttemptExtractor;
use App\Services\PaymentAttempts\Extractors\VivaAttemptExtractor;
use App\Services\PaymentAttempts\Extractors\WhopAttemptExtractor;

return [

    /*
    | Provider value => extractor class (spec 0016). Adding a provider to the shared attempt log
    | is one class implementing AttemptExtractor plus one line here.
    */
    'extractors' => [
        'stripe' => StripeAttemptExtractor::class,
        'revolut' => RevolutAttemptExtractor::class,
        'square' => SquareAttemptExtractor::class,
        'viva' => VivaAttemptExtractor::class,
        'whop' => WhopAttemptExtractor::class,
    ],

    'limits' => [
        'string_length' => 500,
        'max_depth' => 4,
        'max_body_bytes' => 8192,
        'page_rows' => 100,
    ],

];
