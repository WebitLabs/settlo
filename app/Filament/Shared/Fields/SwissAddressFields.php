<?php

namespace App\Filament\Shared\Fields;

use App\Filament\Support\ValidatesOnBlur;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\PostalCode;
use App\Rules\SwissPostalCode;
use App\Services\Geo\AddressSuggestion;
use App\Services\Geo\SwissAddressSearch;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;

/**
 * Swiss address fields with an address search (street and number → postal
 * code, city, canton, commune) and an offline postal code → city/canton/commune
 * autofill. The search and autofill never block the form: when a lookup fails
 * the fields are simply filled by hand.
 */
final class SwissAddressFields
{
    /** Hidden state remembering which values the postal code autofill wrote. */
    public const string AUTOFILL_FIELD = 'address_autofill';

    /**
     * @param  bool  $withCommune  Add a commune picker (filled by the search and autofill).
     * @param  bool  $required  Require street, number, postal code, city (and canton).
     * @param  bool  $withCanton  Add the canton picker.
     * @param  (Closure(Get): bool)|null  $isSwiss  Whether the address is Swiss (defaults to always); a foreign
     *                                              address hides the search and skips the Swiss postal code rules.
     * @param  int|null  $debounceMs  Validate while typing (instead of on blur) with this debounce.
     * @return array<Field>
     */
    public static function make(
        bool $withCommune = false,
        bool $required = true,
        bool $withCanton = true,
        ?Closure $isSwiss = null,
        ?int $debounceMs = null,
    ): array {
        $isSwiss ??= fn (): bool => true;
        $validates = new ValidatesOnBlur(debounceMs: $debounceMs);

        $fields = [
            self::search($withCommune, $withCanton, $isSwiss),
            Hidden::make(self::AUTOFILL_FIELD)->dehydrated(false),
            TextInput::make('street')
                ->label('Street')
                ->required($required)
                ->maxLength(255)
                ->autocomplete('address-line1')
                ->tap($validates),
            TextInput::make('street_number')
                ->label('No.')
                ->required($required)
                ->maxLength(20)
                ->tap($validates),
            TextInput::make('postal_code')
                ->label('Postal code')
                ->required($required)
                ->maxLength(fn (Get $get): int => $isSwiss($get) ? 4 : 20)
                ->rule(new SwissPostalCode, fn (Get $get): bool => $isSwiss($get))
                ->autocomplete('postal-code')
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($withCommune, $withCanton, $isSwiss): void {
                    if ($isSwiss($get)) {
                        self::fillFromPostalCode((string) $state, $get, $set, $withCommune, $withCanton);
                    }
                })
                ->tap(new ValidatesOnBlur(debounceMs: $debounceMs)),
            TextInput::make('city')
                ->label('City')
                ->required($required)
                ->maxLength(255)
                ->autocomplete('address-level2')
                ->tap($validates),
        ];

        if ($withCanton) {
            $fields[] = CantonSelect::make('canton_id', communeField: $withCommune ? 'commune_id' : null)
                ->required($required);
        }

        if ($withCanton && $withCommune) {
            $fields[] = CommuneSelect::make('commune_id', cantonField: 'canton_id', requiredWhenAvailable: $required);
        }

        return $fields;
    }

    /**
     * The locality without the delivery-district number swisstopo appends to
     * some localities: "Lausanne 25" → "Lausanne", "Laax GR 2" → "Laax GR".
     */
    public static function plainLocality(string $locality): string
    {
        $plain = (string) preg_replace('/\s+\d+$/u', '', trim($locality));

        return $plain === '' ? trim($locality) : $plain;
    }

    /**
     * Fill city, canton and commune from the postal code, unless the user typed
     * them by hand (values the autofill wrote earlier may be replaced).
     */
    public static function fillFromPostalCode(string $postalCode, Get $get, Set $set, bool $withCommune = false, bool $withCanton = true): void
    {
        if (! SwissPostalCode::isValid($postalCode)) {
            return;
        }

        $match = PostalCode::bestMatch($postalCode);

        if ($match === null) {
            return;
        }

        $previous = (array) ($get(self::AUTOFILL_FIELD) ?? []);
        $mayReplace = fn (string $field): bool => blank($get($field))
            || (array_key_exists($field, $previous) && (string) $get($field) === (string) $previous[$field]);

        $filled = [];

        if ($mayReplace('city')) {
            $city = self::plainLocality($match->locality);
            $set('city', $city);
            $filled['city'] = $city;
        }

        if ($withCanton && filled($match->canton_code) && $mayReplace('canton_id')) {
            $cantonId = Canton::where('code', $match->canton_code)->value('id');

            if ($cantonId !== null) {
                $cantonChanged = (string) $get('canton_id') !== (string) $cantonId;
                $set('canton_id', $cantonId);
                $filled['canton_id'] = $cantonId;

                if ($withCommune && ($cantonChanged || $mayReplace('commune_id'))) {
                    $communeId = self::communeId($cantonId, $match->bfs_number);
                    $set('commune_id', $communeId);

                    if ($communeId !== null) {
                        $filled['commune_id'] = $communeId;
                    }
                }
            }
        }

        $set(self::AUTOFILL_FIELD, $filled);
    }

    /**
     * Fill every address field from a search suggestion.
     */
    public static function fillFromSuggestion(AddressSuggestion $suggestion, Set $set, bool $withCommune = false, bool $withCanton = true): void
    {
        $set('street', $suggestion->street);
        $set('street_number', $suggestion->streetNumber);
        $set('postal_code', $suggestion->postalCode);
        $set('city', $suggestion->city);
        $filled = ['city' => $suggestion->city];

        if ($withCanton) {
            $cantonId = filled($suggestion->cantonCode) ? Canton::where('code', $suggestion->cantonCode)->value('id') : null;
            $set('canton_id', $cantonId);
            $filled['canton_id'] = $cantonId;

            if ($withCommune) {
                $communeId = $cantonId === null ? null : self::communeId($cantonId, $suggestion->bfsNumber);
                $set('commune_id', $communeId);
                $filled['commune_id'] = $communeId;
            }
        }

        $set(self::AUTOFILL_FIELD, array_filter($filled, filled(...)));
    }

    /**
     * @param  Closure(Get): bool  $isSwiss
     */
    private static function search(bool $withCommune, bool $withCanton, Closure $isSwiss): Select
    {
        return Select::make('address_search')
            ->label('Search your address')
            ->placeholder('Start typing street and number…')
            ->searchPrompt('Start typing street and number…')
            ->noSearchResultsMessage('No address found — fill in the fields below.')
            ->searchable()
            ->searchDebounce(400)
            ->getSearchResultsUsing(fn (string $search): array => collect(app(SwissAddressSearch::class)->search($search))
                ->mapWithKeys(fn (AddressSuggestion $suggestion): array => [$suggestion->id => $suggestion->label])
                ->all())
            ->getOptionLabelUsing(fn (?string $value): ?string => filled($value) ? app(SwissAddressSearch::class)->find($value)?->label : null)
            ->live()
            ->dehydrated(false)
            ->visible(fn (Get $get): bool => $isSwiss($get))
            ->afterStateUpdated(function (?string $state, Set $set, LivewireComponent $livewire, Select $component) use ($withCommune, $withCanton): void {
                $suggestion = filled($state) ? app(SwissAddressSearch::class)->find($state) : null;

                if ($suggestion === null) {
                    return;
                }

                self::fillFromSuggestion($suggestion, $set, $withCommune, $withCanton);

                $base = Str::beforeLast($component->getStatePath(), '.');
                $livewire->resetErrorBag(array_map(
                    fn (string $field): string => "{$base}.{$field}",
                    ['street', 'street_number', 'postal_code', 'city', 'canton_id', 'commune_id'],
                ));
            })
            ->columnSpanFull();
    }

    private static function communeId(string $cantonId, ?string $bfsNumber): ?string
    {
        if (blank($bfsNumber)) {
            return null;
        }

        return Commune::query()
            ->where('canton_id', $cantonId)
            ->where('bfs_number', $bfsNumber)
            ->whereNull('effective_to')
            ->value('id');
    }
}
