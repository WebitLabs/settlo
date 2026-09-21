<?php

use App\Support\Money;

it('formats money in the Swiss style everywhere', function (mixed $value, string $expected) {
    expect(Money::format($value))->toBe($expected);
})->with([
    'thousands' => ['1081.49', "CHF 1'081.49"],
    'millions' => [1234567.5, "CHF 1'234'567.50"],
    'small' => ['9.9', 'CHF 9.90'],
    'zero' => ['0', 'CHF 0.00'],
    'negative' => ['-1500', "CHF -1'500.00"],
    'null is zero' => [null, 'CHF 0.00'],
    'text is zero' => ['not a number', 'CHF 0.00'],
]);

it('never uses the English thousands separator', function () {
    expect(Money::format('1081.49'))->not->toContain(',')
        ->and(Money::number('1081.49'))->toBe("1'081.49");
});

it('formats another currency and whole francs', function () {
    expect(Money::format('1081.49', 'EUR'))->toBe("EUR 1'081.49")
        ->and(Money::rounded('1081.49'))->toBe("CHF 1'081")
        ->and(Money::rounded('1500.50', 'EUR'))->toBe("EUR 1'501");
});

it('trims trailing zeros from quantities and rates', function (mixed $value, string $expected) {
    expect(Money::trimmed($value))->toBe($expected);
})->with([
    'whole' => ['2.00', '2'],
    'half' => ['2.50', '2.5'],
    'rate' => ['8.10', '8.1'],
    'zero' => ['0.00', '0'],
    'minus zero' => ['-0.001', '0'],
]);
