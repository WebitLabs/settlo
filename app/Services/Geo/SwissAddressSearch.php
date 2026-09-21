<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Swiss address autocomplete backed by the free swisstopo GeoAdmin
 * SearchServer. Failures never block a form: they simply yield no suggestions.
 */
class SwissAddressSearch
{
    private const string SEARCH_URL = 'https://api3.geo.admin.ch/rest/services/api/SearchServer';

    private const int MIN_QUERY_LENGTH = 3;

    private const int CACHE_SECONDS = 86400;

    /**
     * @return list<AddressSuggestion>
     */
    public function search(string $query, int $limit = 8): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $cacheKey = 'geo:address:'.md5(mb_strtolower($query).'|'.$limit);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return array_map(AddressSuggestion::fromArray(...), $cached);
        }

        $suggestions = $this->fetch($query, $limit);

        if ($suggestions === null) {
            return [];
        }

        foreach ($suggestions as $suggestion) {
            Cache::put($this->itemKey($suggestion->id), $suggestion->toArray(), self::CACHE_SECONDS);
        }

        Cache::put($cacheKey, array_map(fn (AddressSuggestion $suggestion): array => $suggestion->toArray(), $suggestions), self::CACHE_SECONDS);

        return $suggestions;
    }

    /**
     * A suggestion returned by an earlier search.
     */
    public function find(string $id): ?AddressSuggestion
    {
        $cached = Cache::get($this->itemKey($id));

        return is_array($cached) ? AddressSuggestion::fromArray($cached) : null;
    }

    /**
     * Parse one SearchServer result, or null when it is not a usable address.
     *
     * @param  array<string, mixed>  $result
     */
    public function parse(array $result): ?AddressSuggestion
    {
        $attributes = $result['attrs'] ?? null;

        if (! is_array($attributes) || ! is_string($attributes['label'] ?? null)) {
            return null;
        }

        $label = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($attributes['label']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (preg_match('/^(?<street>.+?)(?:\s+(?<number>\d+[\w.\/-]*))?\s+(?<zip>\d{4})\s+(?<city>.+)$/u', $label, $parts) !== 1) {
            return null;
        }

        $bfsNumber = null;
        $cantonCode = null;

        if (is_string($attributes['detail'] ?? null)
            && preg_match('/\s(?<bfs>\d{1,4})\s\D*?\bch\s(?<canton>[a-z]{2})$/u', trim($attributes['detail']), $detail) === 1) {
            $bfsNumber = $detail['bfs'];
            $cantonCode = strtoupper($detail['canton']);
        }

        $id = (string) ($attributes['featureId'] ?? $result['id'] ?? md5($label));

        return new AddressSuggestion(
            id: $id,
            street: $parts['street'],
            streetNumber: filled($parts['number'] ?? null) ? $parts['number'] : null,
            postalCode: $parts['zip'],
            city: $parts['city'],
            cantonCode: $cantonCode,
            bfsNumber: $bfsNumber,
            label: $label,
        );
    }

    /**
     * @return list<AddressSuggestion>|null null when the service could not be reached
     */
    private function fetch(string $query, int $limit): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(2, 200, throw: false)
                ->acceptJson()
                ->get(self::SEARCH_URL, [
                    'searchText' => $query,
                    'type' => 'locations',
                    'origins' => 'address',
                    'limit' => $limit,
                    'sr' => 4326,
                ]);
        } catch (ConnectionException $exception) {
            report($exception);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            return null;
        }

        $suggestions = [];
        foreach ($results as $result) {
            $suggestion = is_array($result) ? $this->parse($result) : null;

            if ($suggestion !== null) {
                $suggestions[$suggestion->id] = $suggestion;
            }
        }

        return array_values($suggestions);
    }

    private function itemKey(string $id): string
    {
        return 'geo:address:item:'.$id;
    }
}
