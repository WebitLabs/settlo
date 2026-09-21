<?php

namespace App\Console\Commands;

use App\Services\Communes\CommuneImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ImportCommunes extends Command
{
    private const string SNAPSHOT_URL = 'https://www.agvchapp.bfs.admin.ch/api/communes/snapshot';

    protected $signature = 'settlo:import-communes
        {--date=01-01-2026 : Snapshot date (dd-mm-yyyy)}
        {--file= : Read a local BFS snapshot CSV instead of downloading}
        {--export= : Also write the communes to this CSV file (default database/data/communes.csv)}';

    protected $description = 'Import every Swiss commune from the BFS commune register.';

    public function handle(CommuneImporter $importer): int
    {
        $date = (string) $this->option('date');

        try {
            $snapshotDate = CommuneImporter::parseDate($date);
            $csv = $this->snapshotCsv($date);
            $communes = $importer->parseSnapshot($csv);

            if ($communes === []) {
                throw new RuntimeException('The snapshot contains no commune rows.');
            }

            // The importer refuses an empty canton table or a snapshot whose
            // rows resolve to no known canton, so a fresh database cannot
            // report a successful import over an empty commune list.
            $stats = $importer->import($communes, $snapshotDate);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Imported %d communes: %d created, %d updated, %d retired, %d skipped (unknown canton).',
            count($communes), $stats['created'], $stats['updated'], $stats['retired'], $stats['skipped'],
        ));

        if ($stats['skipped'] > 0) {
            $this->warn(sprintf('%d row(s) were skipped because their canton is unknown.', $stats['skipped']));
        }

        if ($this->input->hasParameterOption('--export')) {
            $path = filled($this->option('export')) ? (string) $this->option('export') : database_path('data/communes.csv');
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $importer->toCsv($communes));
            $this->info("Wrote {$path}.");
        }

        return self::SUCCESS;
    }

    private function snapshotCsv(string $date): string
    {
        $file = $this->option('file');

        if (filled($file)) {
            if (! File::exists($file)) {
                throw new RuntimeException("File {$file} does not exist.");
            }

            return File::get($file);
        }

        return Http::timeout(30)
            ->get(self::SNAPSHOT_URL, ['date' => $date])
            ->throw()
            ->body();
    }
}
