<?php

use App\Services\Worldpay\WorldpayPageReply;

it('allows the hosted page host a live account returns', function () {
    $reply = new WorldpayPageReply('https://hpp.worldpay.com/app/hpp/integration/wpg/corporate?OrderKey=x');

    expect($reply->hasAllowedHost('live'))->toBeTrue();
});

it('refuses an unknown host and a non https address', function (string $url, string $environment) {
    expect((new WorldpayPageReply($url))->hasAllowedHost($environment))->toBeFalse();
})->with([
    'unknown host on live' => ['https://evil.example.com/pay', 'live'],
    'try host on live' => ['https://payments-test.worldpay.com/pay', 'live'],
    'plain http on live' => ['http://hpp.worldpay.com/pay', 'live'],
]);
