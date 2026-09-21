<?php

namespace App\Services\Geo;

use App\Models\PostalCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports swisstopo's official directory of localities and postal codes
 * ("Amtliches Ortschaftenverzeichnis", AMTOVZ). Rows are upserted on
 * (postal code, locality, BFS number); rows missing from the directory are
 * removed (nothing references postal codes).
 */
class PostalCodeImporter
{
    /**
     * The export columns of database/data/postal_codes.csv.
     *
     * @var list<string>
     */
    private const array EXPORT_HEADER = ['postal_code', 'locality', 'bfs_number', 'canton_code', 'address_share'];

    /**
     * Parse the ";"-separated AMTOVZ CSV (header "Ortschaftsname;PLZ4;…").
     *
     * @return list<array{postal_code: string, locality: string, bfs_number: string, canton_code: ?string, address_share: string}>
     */
    public function parseDirectory(string $csv): array
    {
        $rows = [];

        foreach ($this->csvRows($csv, ';') as $row) {
            if (! isset($row['PLZ4'], $row['Ortschaftsname'], $row['BFS-Nr'])) {
                throw new RuntimeException('The file is not an AMTOVZ postal code directory.');
            }

            $rows[] = [
                'postal_code' => trim($row['PLZ4']),
                'locality' => trim($row['Ortschaftsname']),
                'bfs_number' => trim($row['BFS-Nr']),
                'canton_code' => filled($row['Kantonskürzel'] ?? null) ? strtoupper(trim($row['Kantonskürzel'])) : null,
                'address_share' => self::parseShare($row['Adressenanteil'] ?? ''),
            ];
        }

        return $this->unique($rows);
    }

    /**
     * Read the committed export file.
     *
     * @return list<array{postal_code: string, locality: string, bfs_number: string, canton_code: ?string, address_share: string}>
     */
    public function readExport(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read postal code file {$path}.");
        }

        return array_map(fn (array $row): array => [
            'postal_code' => (string) $row['postal_code'],
            'locality' => (string) $row['locality'],
            'bfs_number' => (string) $row['bfs_number'],
            'canton_code' => filled($row['canton_code']) ? (string) $row['canton_code'] : null,
            'address_share' => (string) $row['address_share'],
        ], $this->csvRows($contents, ','));
    }

    /**
     * @param  list<array{postal_code: string, locality: string, bfs_number: string, canton_code: ?string, address_share: string}>  $rows
     */
    public function toCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::EXPORT_HEADER, escape: '');

        foreach ($rows as $row) {
            fputcsv($handle, [$row['postal_code'], $row['locality'], $row['bfs_number'], (string) $row['canton_code'], $row['address_share']], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Upsert the rows and remove the ones missing from the list.
     *
     * @param  list<array{postal_code: string, locality: string, bfs_number: string, canton_code: ?string, address_share: string}>  $rows
     * @return array{imported: int, removed: int}
     */
    public function import(array $rows): array
    {
        $existing = PostalCode::query()
            ->get(['id', 'postal_code', 'locality', 'bfs_number'])
            ->keyBy(fn (PostalCode $postalCode): string => self::key($postalCode->only(['postal_code', 'locality', 'bfs_number'])));

        $now = now();
        $records = [];

        foreach ($rows as $row) {
            $key = self::key($row);
            $records[$key] = [
                'id' => $existing->get($key)?->getKey() ?? (string) Str::uuid(),
                ...$row,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $removed = 0;

        DB::transaction(function () use ($records, $existing, &$removed): void {
            foreach (array_chunk(array_values($records), 500) as $chunk) {
                PostalCode::query()->upsert(
                    $chunk,
                    ['postal_code', 'locality', 'bfs_number'],
                    ['canton_code', 'address_share', 'updated_at'],
                );
            }

            $staleIds = $existing
                ->reject(fn (PostalCode $postalCode, string $key): bool => isset($records[$key]))
                ->pluck('id')
                ->all();

            foreach (array_chunk($staleIds, 500) as $chunk) {
                $removed += PostalCode::query()->whereKey($chunk)->delete();
            }
        });

        return ['imported' => count($records), 'removed' => $removed];
    }

    /**
     * "99.695 %" → "99.70", "100 %" → "100.00".
     */
    public static function parseShare(string $share): string
    {
        $number = (float) str_replace([',', '%', ' '], ['.', '', ''], $share);

        return number_format(min(100, max(0, $number)), 2, '.', '');
    }

    /**
     * @param  array{postal_code: string, locality: string, bfs_number: string}  $row
     */
    private static function key(array $row): string
    {
        return $row['postal_code'].'|'.$row['locality'].'|'.$row['bfs_number'];
    }

    /**
     * Drop duplicate keys (the directory lists a locality once per
     * "Zusatzziffer"), keeping the largest address share, sorted by postal code.
     *
     * @param  list<array{postal_code: string, locality: string, bfs_number: string, canton_code: ?string, address_share: string}>  $rows
     * @return list<array{postal_code: string, locality: string, bfs_number: string, canton_code: ?string, address_share: string}>
     */
    private function unique(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $key = self::key($row);

            if (! isset($unique[$key]) || (float) $row['address_share'] > (float) $unique[$key]['address_share']) {
                $unique[$key] = $row;
            }
        }

        $unique = array_values($unique);

        usort($unique, fn (array $a, array $b): int => [$a['postal_code'], Str::ascii($a['locality']), (int) $a['bfs_number']]
            <=> [$b['postal_code'], Str::ascii($b['locality']), (int) $b['bfs_number']]);

        return $unique;
    }

    /**
     * @return list<array<string, string>>
     */
    private function csvRows(string $csv, string $separator): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $header = array_map('trim', str_getcsv((string) array_shift($lines), $separator, escape: ''));

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, $separator, escape: '');
            $rows[] = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
        }

        return $rows;
    }
}
