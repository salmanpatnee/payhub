<?php

use App\Services\Mpgs\MpgsAmount;

it('converts integer minor units to a gateway decimal string', function (int $minor, string $expected) {
    expect(MpgsAmount::toGateway($minor))->toBe($expected);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [100, '1.00'],
    [1050, '10.50'],
    [123456, '1234.56'],
    // PKR also has 2 decimals, so large nominal PKR amounts convert the same way.
    [25000000, '250000.00'],
]);

it('parses a gateway amount back to minor units', function (mixed $gateway, ?int $expected) {
    expect(MpgsAmount::toMinorUnits($gateway))->toBe($expected);
})->with([
    ['10.50', 1050],
    ['10.5', 1050],
    [10.5, 1050],
    [10, 1000],
    ['0.05', 5],
    ['10.505', null],
    ['abc', null],
    ['-1.00', null],
    [null, null],
    [[], null],
]);

it('round trips every amount without float drift', function () {
    foreach ([1, 7, 99, 100, 101, 1999, 100000, 999999, 99999999] as $minor) {
        expect(MpgsAmount::toMinorUnits(MpgsAmount::toGateway($minor)))->toBe($minor);
    }
});
