<?php

use App\Services\Nmi\NmiAmount;

it('converts integer minor units to NMI decimal strings', function (int $cents, string $expected) {
    expect(NmiAmount::centsToDecimal($cents))->toBe($expected);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [100, '1.00'],
    [1050, '10.50'],
    [123456, '1234.56'],
]);

it('parses decimal strings back to cents', function (?string $value, ?int $expected) {
    expect(NmiAmount::decimalToCents($value))->toBe($expected);
})->with([
    ['12.34', 1234],
    ['12.3', 1230],
    ['12.3400', 1234],
    ['12', 1200],
    ['0.05', 5],
    [' 1.00 ', 100],
    // fail closed
    ['12.345', null],
    ['-1.00', null],
    ['1e3', null],
    ['abc', null],
    ['', null],
    [null, null],
]);

it('round trips amounts without drift', function () {
    foreach ([1, 7, 99, 100, 101, 1999, 100000, 999999, 99999999] as $cents) {
        expect(NmiAmount::decimalToCents(NmiAmount::centsToDecimal($cents)))->toBe($cents);
    }
});
