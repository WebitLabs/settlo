<?php

use App\Models\Canton;
use App\Models\CantonFiscalConfig;
use App\Models\Commune;
use App\Services\Communes\CommuneImporter;
use Database\Seeders\CantonSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(CantonSeeder::class);
    $this->aargau = Canton::where('code', 'AG')->firstOrFail();
    $this->fixture = base_path('tests/Fixtures/bfs_communes_snapshot.csv');
});

it('refuses to import when no cantons are seeded', function () {
    CantonFiscalConfig::query()->delete();
    Commune::query()->delete();
    Canton::query()->delete();

    $this->artisan('settlo:import-communes', ['--file' => $this->fixture, '--date' => '01-01-2026'])
        ->expectsOutputToContain('No cantons are present')
        ->assertFailed();
});

it('refuses a snapshot whose cantons are all unknown instead of retiring every commune', function () {
    $kept = Commune::create([
        'canton_id' => $this->aargau->getKey(), 'name' => 'Aarau', 'bfs_number' => '4001',
        'tax_multiplier' => 97, 'multiplier_is_estimated' => false, 'effective_from' => '2020-01-01',
    ]);

    $import = fn () => app(CommuneImporter::class)->import(
        [['bfs_number' => '9001', 'name' => 'Nowhere', 'canton_code' => 'XX']],
        CommuneImporter::parseDate('2026-01-01'),
    );

    expect($import)->toThrow(RuntimeException::class, 'All 1 commune rows were skipped')
        ->and($kept->fresh()->effective_to)->toBeNull();
});

it('resolves each commune\'s canton through its district', function () {
    $communes = app(CommuneImporter::class)->parseSnapshot((string) file_get_contents($this->fixture));

    // A commune sharing its historical code with the canton row must still
    // resolve through its district.
    expect($communes)->toBe([
        ['bfs_number' => '4001', 'name' => 'Aarau', 'canton_code' => 'AG'],
        ['bfs_number' => '4002', 'name' => 'Biberstein', 'canton_code' => 'AG'],
        ['bfs_number' => '4003', 'name' => 'Buchs (AG)', 'canton_code' => 'AG'],
    ]);
});

it('imports communes with estimated multipliers, keeps real ones and retires removed communes', function () {
    $default = (string) $this->aargau->fiscalConfigForYear(2026)->communal_multiplier_default;
    $real = Commune::create([
        'canton_id' => $this->aargau->getKey(), 'name' => 'Aarau (old name)', 'bfs_number' => '4001',
        'tax_multiplier' => 97, 'multiplier_is_estimated' => false,
        'effective_from' => '2020-01-01',
    ]);
    $removed = Commune::create([
        'canton_id' => $this->aargau->getKey(), 'name' => 'Merged Away', 'bfs_number' => '4999',
        'tax_multiplier' => 110, 'multiplier_is_estimated' => false,
        'effective_from' => '2020-01-01',
    ]);

    $this->artisan('settlo:import-communes', ['--file' => $this->fixture, '--date' => '01-01-2026'])
        ->expectsOutputToContain('Imported 3 communes: 2 created, 1 updated, 1 retired')
        ->assertSuccessful();

    $aarau = $real->fresh();
    expect($aarau->name)->toBe('Aarau')
        ->and((float) $aarau->tax_multiplier)->toBe(97.0)
        ->and($aarau->multiplier_is_estimated)->toBeFalse()
        ->and($aarau->effective_from->toDateString())->toBe('2020-01-01')
        ->and($aarau->effective_to)->toBeNull();

    $biberstein = Commune::where('bfs_number', '4002')->firstOrFail();
    expect($biberstein->canton_id)->toBe($this->aargau->getKey())
        ->and((string) $biberstein->tax_multiplier)->toBe($default)
        ->and($biberstein->multiplier_is_estimated)->toBeTrue()
        ->and($biberstein->effective_from->toDateString())->toBe('2026-01-01');

    expect($removed->fresh()->effective_to->toDateString())->toBe('2025-12-31');

    // Running the import again is idempotent.
    $this->artisan('settlo:import-communes', ['--file' => $this->fixture, '--date' => '01-01-2027'])->assertSuccessful();
    expect(Commune::count())->toBe(4)
        ->and($real->fresh()->effective_from->toDateString())->toBe('2020-01-01')
        ->and($biberstein->fresh()->effective_from->toDateString())->toBe('2026-01-01')
        ->and($removed->fresh()->effective_to->toDateString())->toBe('2025-12-31');
});

it('downloads the snapshot and exports the communes file', function () {
    Http::fake(['www.agvchapp.bfs.admin.ch/*' => Http::response((string) file_get_contents($this->fixture))]);
    $export = storage_path('framework/testing/communes-export.csv');
    File::delete($export);

    $this->artisan('settlo:import-communes', ['--export' => $export])->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api/communes/snapshot') && $request['date'] === '01-01-2026');
    expect(File::get($export))->toBe("bfs_number,name,canton_code\n4001,Aarau,AG\n4002,Biberstein,AG\n4003,\"Buchs (AG)\",AG\n")
        ->and(app(CommuneImporter::class)->readExport($export))->toHaveCount(3);

    File::delete($export);
});

it('fails cleanly when the snapshot file is missing', function () {
    $this->artisan('settlo:import-communes', ['--file' => '/nonexistent/snapshot.csv'])
        ->expectsOutputToContain('does not exist')
        ->assertFailed();
});
