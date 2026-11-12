<?php

use App\Models\SquareAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Square\ApplePay\ApplePayClient;
use Square\ApplePay\Requests\RegisterDomainRequest;
use Square\Exceptions\SquareApiException;
use Square\SquareClient;
use Square\Types\RegisterDomainResponse;

uses(RefreshDatabase::class);

/**
 * Bind a SquareClient whose applePay->registerDomain records each domain and token used.
 */
function fakeSquareApplePay(array &$calls, bool $fail = false): void
{
    app()->bind(SquareClient::class, function ($app, array $args) use (&$calls, $fail) {
        $applePay = Mockery::mock(ApplePayClient::class);
        $applePay->shouldReceive('registerDomain')->andReturnUsing(function (RegisterDomainRequest $request) use (&$calls, $args, $fail) {
            $calls[] = ['domain' => $request->getDomainName(), 'token' => $args['token']];

            if ($fail) {
                throw new SquareApiException('Domain is misconfigured for Apple Pay.', 400, null);
            }

            return new RegisterDomainResponse(['status' => 'VERIFIED']);
        });

        $square = Mockery::mock(SquareClient::class);
        $square->applePay = $applePay;

        return $square;
    });
}

it('registers the domain for every active square account with its own token', function () {
    $first = SquareAccount::factory()->create(['is_active' => true]);
    $first->access_token = 'token-one';
    $first->save();
    $second = SquareAccount::factory()->create(['is_active' => true]);
    $second->access_token = 'token-two';
    $second->save();
    SquareAccount::factory()->create(['is_active' => false]);

    $calls = [];
    fakeSquareApplePay($calls);

    $this->artisan('square:register-apple-pay-domain', ['--domain' => 'pay.example.com'])
        ->assertSuccessful();

    expect($calls)->toBe([
        ['domain' => 'pay.example.com', 'token' => 'token-one'],
        ['domain' => 'pay.example.com', 'token' => 'token-two'],
    ]);
});

it('registers only the given account', function () {
    SquareAccount::factory()->create();
    $chosen = SquareAccount::factory()->create();
    $chosen->access_token = 'chosen-token';
    $chosen->save();

    $calls = [];
    fakeSquareApplePay($calls);

    $this->artisan('square:register-apple-pay-domain', ['account' => $chosen->id, '--domain' => 'pay.example.com'])
        ->assertSuccessful();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['token'])->toBe('chosen-token');
});

it('fails without showing square text when square refuses the domain', function () {
    SquareAccount::factory()->create();

    $calls = [];
    fakeSquareApplePay($calls, fail: true);

    $this->artisan('square:register-apple-pay-domain', ['--domain' => 'pay.example.com'])
        ->doesntExpectOutputToContain('misconfigured')
        ->assertFailed();
});

it('rejects a domain given as a url', function () {
    SquareAccount::factory()->create();

    $this->artisan('square:register-apple-pay-domain', ['--domain' => 'https://pay.example.com/x'])
        ->assertFailed();
});
