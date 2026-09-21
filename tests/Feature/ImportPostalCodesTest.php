<?php

use App\Models\PostalCode;
use App\Services\Geo\PostalCodeImporter;
use Database\Seeders\PostalCodeSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->fixture = base_path('tests/Fixtures/amtovz_postal_codes.csv');
});

it('parses the swisstopo directory, merging duplicate localities', function () {
    $rows = app(PostalCodeImporter::class)->parseDirectory((string) file_get_contents($this->fixture));

    expect($rows)->toBe([
        ['postal_code' => '5000', 'locality' => 'Aarau', 'bfs_number' => '4001', 'canton_code' => 'AG', 'address_share' => '99.70'],
        ['postal_code' => '5000', 'locality' => 'Aarau', 'bfs_number' => '4012', 'canton_code' => 'AG', 'address_share' => '0.31'],
        ['postal_code' => '8001', 'locality' => 'Zürich', 'bfs_number' => '261', 'canton_code' => 'ZH', 'address_share' => '100.00'],
        ['postal_code' => '9485', 'locality' => 'Nendeln', 'bfs_number' => '7007', 'canton_code' => null, 'address_share' => '99.05'],
    ]);
});

it('imports a local file, exports it and removes stale rows', function () {
    $stale = PostalCode::factory()->create(['postal_code' => '1234', 'locality' => 'Gone', 'bfs_number' => '1']);
    $export = storage_path('framework/testing/postal_codes.csv');

    $this->artisan('settlo:import-postal-codes', ['--file' => $this->fixture, '--export' => $export])
        ->expectsOutputToContain('Imported 4 postal code localities, removed 1.')
        ->assertSuccessful();

    expect(PostalCode::count())->toBe(4)
        ->and(PostalCode::whereKey($stale->getKey())->exists())->toBeFalse()
        ->and(File::get($export))->toStartWith("postal_code,locality,bfs_number,canton_code,address_share\n5000,Aarau,4001,AG,99.70\n");

    File::delete($export);
    Http::assertNothingSent();
});

it('downloads and unzips the directory', function () {
    $zipPath = storage_path('framework/testing/amtovz.zip');
    File::ensureDirectoryExists(dirname($zipPath));
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('AMTOVZ_CSV_WGS84/AMTOVZ_CSV_WGS84.csv', (string) file_get_contents($this->fixture));
    $zip->close();

    Http::fake(['data.geo.admin.ch/*' => Http::response((string) file_get_contents($zipPath))]);

    $this->artisan('settlo:import-postal-codes')->assertSuccessful();

    expect(PostalCode::bestMatch('5000'))
        ->locality->toBe('Aarau')
        ->canton_code->toBe('AG')
        ->bfs_number->toBe('4001');

    File::delete($zipPath);
});

it('fails cleanly for a file that is not a postal code directory', function () {
    $this->artisan('settlo:import-postal-codes', ['--file' => base_path('tests/Fixtures/bfs_communes_snapshot.csv')])
        ->assertFailed();

    expect(PostalCode::count())->toBe(0);
});

it('seeds every Swiss postal code from the committed file', function () {
    $this->seed(PostalCodeSeeder::class);

    expect(PostalCode::count())->toBeGreaterThan(5000)
        ->and(PostalCode::bestMatch('5000'))->locality->toBe('Aarau')->canton_code->toBe('AG')->bfs_number->toBe('4001')
        ->and(PostalCode::bestMatch('8001'))->locality->toBe('Zürich')->canton_code->toBe('ZH')
        ->and(PostalCode::bestMatch('9485'))->canton_code->toBeNull()
        ->and(PostalCode::bestMatch('0000'))->toBeNull();
});
