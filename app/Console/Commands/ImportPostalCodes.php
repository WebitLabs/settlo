<?php

namespace App\Console\Commands;

use App\Services\Geo\PostalCodeImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class ImportPostalCodes extends Command
{
    private const string DIRECTORY_URL = 'https://data.geo.admin.ch/ch.swisstopo-vd.ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz/ortschaftenverzeichnis_plz_4326.csv.zip';

    protected $signature = 'settlo:import-postal-codes
        {--file= : Read a local AMTOVZ file (.csv or .csv.zip) instead of downloading}
        {--export= : Also write the postal codes to this CSV file (default database/data/postal_codes.csv)}';

    protected $description = 'Import every Swiss postal code from the swisstopo locality directory.';

    public function handle(PostalCodeImporter $importer): int
    {
        try {
            $rows = $importer->parseDirectory($this->directoryCsv());

            // An empty directory would delete every postal code on record,
            // so it is a failure rather than a no-op import.
            if ($rows === []) {
                throw new RuntimeException('The locality directory contains no postal code rows.');
            }

            $stats = $importer->import($rows);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Imported %d postal code localities, removed %d.', $stats['imported'], $stats['removed']));

        if ($this->input->hasParameterOption('--export')) {
            $path = filled($this->option('export')) ? (string) $this->option('export') : database_path('data/postal_codes.csv');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $importer->toCsv($rows));
            $this->info("Wrote {$path}.");
        }

        return self::SUCCESS;
    }

    private function directoryCsv(): string
    {
        $file = $this->option('file');

        if (filled($file)) {
            if (! File::exists($file)) {
                throw new RuntimeException("File {$file} does not exist.");
            }

            return Str::endsWith(strtolower($file), '.zip') ? $this->extractCsv($file) : File::get($file);
        }

        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);
        $zipPath = $directory.'/postal-codes-'.Str::random(8).'.zip';

        try {
            File::put($zipPath, Http::timeout(60)->get(self::DIRECTORY_URL)->throw()->body());

            return $this->extractCsv($zipPath);
        } finally {
            File::delete($zipPath);
        }
    }

    private function extractCsv(string $zipPath): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open {$zipPath}.");
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);

                if (Str::endsWith(strtolower($name), '.csv')) {
                    return (string) $zip->getFromIndex($index);
                }
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException('The archive contains no CSV file.');
    }
}
