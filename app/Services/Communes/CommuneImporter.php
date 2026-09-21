<?php

namespace App\Services\Communes;

use App\Models\Canton;
use App\Models\CantonFiscalConfig;
use App\Models\Commune;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports the Swiss commune register (BFS "Schweizerisches Gemeindeverzeichnis").
 *
 * Communes are upserted on (canton, BFS number). A commune that is new gets the
 * canton's default communal multiplier and is flagged as estimated; a commune
 * with a real multiplier keeps it. An existing commune keeps its effective_from
 * date. Communes missing from the snapshot are retired (effective_to), never
 * deleted, because tax profiles reference them.
 */
class CommuneImporter
{
    /** BFS level of a commune row (1 = canton, 2 = district). */
    private const int LEVEL_COMMUNE = 3;

    private const int LEVEL_DISTRICT = 2;

    private const int LEVEL_CANTON = 1;

    /** Multiplier used when a canton has no fiscal config at all. */
    private const string FALLBACK_MULTIPLIER = '100';

    /**
     * Parse a BFS snapshot CSV into commune rows sorted by canton and name.
     *
     * @return list<array{bfs_number: string, name: string, canton_code: string}>
     */
    public function parseSnapshot(string $csv): array
    {
        $rows = $this->csvRows($csv);

        // Historical codes are only unique within a level (a district and a
        // commune can share one), so rows are indexed per level.
        $byLevelAndCode = [];
        foreach ($rows as $row) {
            $byLevelAndCode[(int) $row['Level']][$row['HistoricalCode']] = $row;
        }

        $communes = [];
        foreach ($rows as $row) {
            if ((int) $row['Level'] !== self::LEVEL_COMMUNE) {
                continue;
            }

            $cantonCode = $this->resolveCantonCode($row, $byLevelAndCode);

            if ($cantonCode === null) {
                throw new RuntimeException("Could not resolve the canton of commune {$row['Name']} ({$row['BfsCode']}).");
            }

            $communes[] = [
                'bfs_number' => (string) $row['BfsCode'],
                'name' => (string) $row['Name'],
                'canton_code' => $cantonCode,
            ];
        }

        return $this->sort($communes);
    }

    /**
     * Read the committed "bfs_number,name,canton_code" file.
     *
     * @return list<array{bfs_number: string, name: string, canton_code: string}>
     */
    public function readExport(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read commune file {$path}.");
        }

        return array_map(fn (array $row): array => [
            'bfs_number' => (string) $row['bfs_number'],
            'name' => (string) $row['name'],
            'canton_code' => (string) $row['canton_code'],
        ], $this->csvRows($contents));
    }

    /**
     * @param  list<array{bfs_number: string, name: string, canton_code: string}>  $communes
     */
    public function toCsv(array $communes): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['bfs_number', 'name', 'canton_code'], escape: '');

        foreach ($this->sort($communes) as $commune) {
            fputcsv($handle, [$commune['bfs_number'], $commune['name'], $commune['canton_code']], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Upsert the communes and retire the ones missing from the list.
     *
     * @param  list<array{bfs_number: string, name: string, canton_code: string}>  $communes
     * @return array{created: int, updated: int, retired: int, skipped: int}
     */
    public function import(array $communes, CarbonImmutable $date): array
    {
        $cantonIds = Canton::query()->pluck('id', 'code')->all();

        // A fresh database has no cantons, so every row would be counted as
        // "skipped" and the import would report success over an empty table.
        if ($cantonIds === []) {
            throw new RuntimeException('No cantons are present. Seed the reference data (php artisan db:seed --class=ReferenceDataSeeder --force) before importing communes.');
        }

        $defaults = $this->defaultMultipliers($date->year);
        $existing = Commune::query()
            ->get(['id', 'canton_id', 'bfs_number', 'tax_multiplier', 'multiplier_is_estimated', 'effective_from'])
            ->keyBy(fn (Commune $commune): string => $commune->canton_id.'|'.$commune->bfs_number);

        $now = now();
        $rows = [];
        $seen = [];
        $stats = ['created' => 0, 'updated' => 0, 'retired' => 0, 'skipped' => 0];

        foreach ($communes as $commune) {
            $cantonId = $cantonIds[$commune['canton_code']] ?? null;

            if ($cantonId === null) {
                $stats['skipped']++;

                continue;
            }

            $key = $cantonId.'|'.$commune['bfs_number'];
            $current = $existing->get($key);
            $keepsRealMultiplier = $current !== null && ! $current->multiplier_is_estimated;

            $rows[$key] = [
                'id' => $current?->getKey() ?? (string) Str::uuid(),
                'canton_id' => $cantonId,
                'bfs_number' => $commune['bfs_number'],
                'name' => $commune['name'],
                'tax_multiplier' => $keepsRealMultiplier
                    ? (string) $current->tax_multiplier
                    : ($defaults[$cantonId] ?? self::FALLBACK_MULTIPLIER),
                'multiplier_is_estimated' => ! $keepsRealMultiplier,
                'effective_from' => $current?->effective_from?->toDateString() ?? $date->toDateString(),
                'effective_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $seen[$key] = true;
            $current === null ? $stats['created']++ : $stats['updated']++;
        }

        // Nothing matched: writing this would retire every commune on record.
        // Refuse before the transaction instead of silently emptying the table.
        if ($rows === [] && $communes !== []) {
            throw new RuntimeException(sprintf(
                'All %d commune rows were skipped: none of their canton codes match the %d canton(s) on record.',
                count($communes),
                count($cantonIds),
            ));
        }

        DB::transaction(function () use ($rows, $existing, $seen, $date, &$stats): void {
            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                Commune::query()->upsert(
                    $chunk,
                    ['canton_id', 'bfs_number'],
                    ['name', 'tax_multiplier', 'multiplier_is_estimated', 'effective_from', 'effective_to', 'updated_at'],
                );
            }

            $retiredIds = $existing
                ->reject(fn (Commune $commune, string $key): bool => isset($seen[$key]))
                ->pluck('id')
                ->all();

            foreach (array_chunk($retiredIds, 500) as $chunk) {
                $stats['retired'] += Commune::query()
                    ->whereKey($chunk)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $date->subDay()->toDateString(), 'updated_at' => now()]);
            }
        });

        return $stats;
    }

    /**
     * Parse "d-m-Y" (the BFS API format) or "Y-m-d".
     */
    public static function parseDate(string $date): CarbonImmutable
    {
        $format = preg_match('/^\d{2}-\d{2}-\d{4}$/', $date) === 1 ? 'd-m-Y' : 'Y-m-d';

        return CarbonImmutable::createFromFormat($format, $date)->startOfDay();
    }

    /**
     * The canton code of a commune: its parent is a district (level 2) whose
     * parent is the canton (level 1). A commune directly under a canton is
     * accepted defensively.
     *
     * @param  array<string, string>  $row
     * @param  array<int, array<string, array<string, string>>>  $byLevelAndCode
     */
    private function resolveCantonCode(array $row, array $byLevelAndCode): ?string
    {
        $district = $byLevelAndCode[self::LEVEL_DISTRICT][$row['Parent']] ?? null;
        $canton = $district !== null
            ? ($byLevelAndCode[self::LEVEL_CANTON][$district['Parent']] ?? null)
            : ($byLevelAndCode[self::LEVEL_CANTON][$row['Parent']] ?? null);

        return $canton['ShortName'] ?? null;
    }

    /**
     * Each canton's default communal multiplier for the fiscal year, falling back
     * to the most recent year on record.
     *
     * @return array<string, string>
     */
    private function defaultMultipliers(int $year): array
    {
        $defaults = [];

        CantonFiscalConfig::query()
            ->orderBy('year')
            ->get(['canton_id', 'year', 'communal_multiplier_default'])
            ->each(function (CantonFiscalConfig $config) use ($year, &$defaults): void {
                if ($config->year <= $year || ! isset($defaults[$config->canton_id])) {
                    $defaults[$config->canton_id] = (string) $config->communal_multiplier_default;
                }
            });

        return $defaults;
    }

    /**
     * @return list<array<string, string>>
     */
    private function csvRows(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), escape: '');

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, escape: '');
            $rows[] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
        }

        return $rows;
    }

    /**
     * @param  list<array{bfs_number: string, name: string, canton_code: string}>  $communes
     * @return list<array{bfs_number: string, name: string, canton_code: string}>
     */
    private function sort(array $communes): array
    {
        usort($communes, fn (array $a, array $b): int => [$a['canton_code'], Str::ascii($a['name']), $a['name'], (int) $a['bfs_number']]
            <=> [$b['canton_code'], Str::ascii($b['name']), $b['name'], (int) $b['bfs_number']]);

        return $communes;
    }
}
