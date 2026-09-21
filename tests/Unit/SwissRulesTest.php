<?php

use App\Rules\SwissPostalCode;
use App\Rules\SwissUid;
use App\Rules\SwissVatNumber;
use App\Rules\ValidIban;
use Illuminate\Support\Facades\Validator;

it('validates Swiss postal codes', function (string $postalCode, bool $valid) {
    expect(SwissPostalCode::isValid($postalCode))->toBe($valid)
        ->and(Validator::make(['postal_code' => $postalCode], ['postal_code' => [new SwissPostalCode]])->passes())->toBe($valid);
})->with([
    'Zürich' => ['8001', true],
    'Geneva' => ['1201', true],
    'Vaduz (LI)' => ['9490', true],
    'leading zero' => ['0800', false],
    'five digits' => ['80001', false],
    'three digits' => ['800', false],
    'letters' => ['ZZ99', false],
]);

it('validates UIDs with the eCH-0097 check digit', function (string $uid, ?string $normalized) {
    expect(SwissUid::normalize($uid))->toBe($normalized)
        ->and(SwissUid::isValid($uid))->toBe($normalized !== null)
        ->and(Validator::make(['uid' => $uid], ['uid' => [new SwissUid]])->passes())->toBe($normalized !== null);
})->with([
    'Migros' => ['CHE-105.829.940', 'CHE-105.829.940'],
    'demo seed' => ['CHE-148.830.302', 'CHE-148.830.302'],
    'compact' => ['CHE148830302', 'CHE-148.830.302'],
    'spaces' => ['CHE 148 830 302', 'CHE-148.830.302'],
    'lower case' => ['che-105.829.940', 'CHE-105.829.940'],
    'corrected check digit' => ['CHE-123.456.788', 'CHE-123.456.788'],
    'wrong check digit' => ['CHE-123.456.789', null],
    'too short' => ['CHE-12', null],
    'wrong prefix' => ['ABC-105.829.940', null],
    'garbage' => ['not a uid', null],
]);

it('rejects UIDs whose check digit would be 10', function () {
    $base = collect(range(10000000, 10000100))
        ->map(fn (int $number): string => (string) $number)
        ->first(function (string $digits): bool {
            $sum = 0;
            foreach ([5, 4, 3, 2, 7, 6, 5, 4] as $index => $weight) {
                $sum += (int) $digits[$index] * $weight;
            }

            return 11 - ($sum % 11) === 10;
        });

    foreach (range(0, 9) as $checkDigit) {
        expect(SwissUid::isValid("CHE{$base}{$checkDigit}"))->toBeFalse();
    }
});

it('validates and normalises Swiss VAT numbers', function (string $vatNumber, ?string $normalized) {
    expect(SwissVatNumber::normalize($vatNumber))->toBe($normalized)
        ->and(Validator::make(['vat' => $vatNumber], ['vat' => [new SwissVatNumber]])->passes())->toBe($normalized !== null);
})->with([
    'with MWST' => ['CHE-105.829.940 MWST', 'CHE-105.829.940 MWST'],
    'with TVA' => ['CHE-105.829.940 TVA', 'CHE-105.829.940 TVA'],
    'with lower-case IVA' => ['che105829940 iva', 'CHE-105.829.940 IVA'],
    'without suffix defaults to MWST' => ['CHE-105.829.940', 'CHE-105.829.940 MWST'],
    'without suffix or punctuation' => ['che105829940', 'CHE-105.829.940 MWST'],
    'bad check digit' => ['CHE-123.456.789 MWST', null],
    'unknown suffix' => ['CHE-105.829.940 VAT', null],
    'not a VAT number' => ['INVALIDVAT', null],
]);

it('accepts letters in the account part of CH/LI IBANs (ISO 13616)', function (string $iban, bool $valid) {
    expect(ValidIban::isValid($iban))->toBe($valid)
        ->and(Validator::make(['iban' => $iban], ['iban' => [new ValidIban]])->passes())->toBe($valid);
})->with([
    'digits only' => ['CH93 0076 2011 6238 5295 7', true],
    'CH with letters in the account part' => ['CH24 0076 2ABC 1234 5678 9', true],
    'CH with lower-case letters' => ['ch2400762abc123456789', true],
    'LI with letters in the account part' => ['LI39 0881 0A1B 2C3D 4E5F 6', true],
    'letters in the check digits' => ['CHA40076 2ABC 1234 5678 9', false],
    'letters in the bank code' => ['CH24 007A 2ABC 1234 5678 9', false],
    'letters with a bad checksum' => ['CH24 0076 2ABD 1234 5678 9', false],
    'too long' => ['CH24 0076 2ABC 1234 5678 90', false],
]);

it('formats an alphanumeric IBAN in upper-case blocks of four', function () {
    expect(ValidIban::format('ch2400762abc123456789'))->toBe('CH24 0076 2ABC 1234 5678 9')
        ->and(ValidIban::format(''))->toBe('');
});
