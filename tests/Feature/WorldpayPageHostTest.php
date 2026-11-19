<?php

use App\Services\Worldpay\WorldpayPageReply;

it('allows a hosted page on a Worldpay subdomain, on both environments', function (string $url, string $environment) {
    expect((new WorldpayPageReply($url))->hasAllowedHost($environment))->toBeTrue();
})->with([
    'live hpp' => ['https://hpp.worldpay.com/app/hpp/integration/wpg/corporate?OrderKey=x', 'live'],
    'live payments' => ['https://payments.worldpay.com/app/hpp/integration/transaction/abc', 'live'],
    'try payments-test' => ['https://payments-test.worldpay.com/pay', 'try'],
    'try hpp-sandbox' => ['https://hpp-sandbox.worldpay.com/pay', 'try'],
    'a new subdomain' => ['https://new-host.worldpay.com/pay', 'live'],
    'upper case host' => ['https://HPP.Worldpay.com/pay', 'live'],
    'explicit 443' => ['https://hpp.worldpay.com:443/pay', 'live'],
]);

it('refuses a host that is not a Worldpay subdomain, a non https address and an odd port', function (string $url, string $environment) {
    expect((new WorldpayPageReply($url))->hasAllowedHost($environment))->toBeFalse();
})->with([
    'unknown host' => ['https://evil.example.com/pay', 'live'],
    'lookalike suffix' => ['https://evilworldpay.com/pay', 'live'],
    'worldpay as a subdomain of another domain' => ['https://hpp.worldpay.com.evil.test/pay', 'live'],
    'userinfo trick' => ['https://hpp.worldpay.com@evil.test/pay', 'live'],
    'bare domain' => ['https://worldpay.com/pay', 'live'],
    'plain http' => ['http://hpp.worldpay.com/pay', 'live'],
    'port 8443' => ['https://hpp.worldpay.com:8443/pay', 'live'],
    'not a url' => ['not a url', 'live'],
]);
