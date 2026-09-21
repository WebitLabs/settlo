<?php

use App\Services\Invoicing\InvoiceTotals;

it('computes line net amounts', function (mixed $quantity, mixed $price, string $expected) {
    expect(InvoiceTotals::lineNet($quantity, $price))->toBe($expected);
})->with([
    'simple' => ['3', '19.99', '59.97'],
    'fractional quantity rounds half up' => ['0.5', '0.05', '0.03'],
    'numbers' => [2, 100, '200.00'],
    'non-numeric quantity' => ['abc', '19.99', '0.00'],
    'empty price' => ['2', null, '0.00'],
]);

it('computes line VAT', function (string $net, mixed $rate, string $expected) {
    expect(InvoiceTotals::lineVat($net, $rate))->toBe($expected);
})->with([
    'standard rate' => ['59.97', '8.1', '4.86'],
    'reduced rate' => ['100.00', '2.6', '2.60'],
    'zero rate' => ['100.00', '0', '0.00'],
    'non-numeric rate' => ['100.00', 'x', '0.00'],
]);

it('normalizes VAT rates', function (mixed $rate, string $expected) {
    expect(InvoiceTotals::normalizeRate($rate))->toBe($expected);
})->with([
    ['8.100', '8.1'],
    ['0.00', '0'],
    [2.6, '2.6'],
    [null, '0'],
]);

it('totals lines with a per-rate breakdown, highest rate first', function () {
    $totals = InvoiceTotals::forLines([
        ['quantity' => '2', 'unit_price' => '100.00', 'vat_rate' => '8.1'],
        ['quantity' => '1', 'unit_price' => '800.45', 'vat_rate' => '8.10'],
        ['quantity' => '1', 'unit_price' => '50', 'vat_rate' => '2.6'],
    ]);

    expect($totals['subtotal'])->toBe('1050.45')
        ->and($totals['vat'])->toBe('82.34')
        ->and($totals['total'])->toBe('1132.79')
        ->and(array_keys($totals['breakdown']))->toBe(['8.1', '2.6'])
        ->and($totals['breakdown']['8.1'])->toBe(['rate' => '8.1', 'base' => '1000.45', 'vat' => '81.04']);
});

it('rounds negative amounts away from zero', function () {
    expect(InvoiceTotals::round('-1.005'))->toBe('-1.01');
});
