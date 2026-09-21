<?php

namespace App\Filament\Shared\Schemas;

use App\Enums\MaritalStatus;
use App\Filament\Shared\Fields\CantonSelect;
use App\Filament\Support\ProfileFields;
use App\Filament\Support\ValidatesOnBlur;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Reporting\BusinessMetricsResult;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The personal tax profile fields (tax residence, household, pension, other
 * income) plus a read-only summary of the income from the owner's businesses.
 */
final class TaxProfileFields
{
    /**
     * The tax profile attributes these fields write (the canton is written from
     * the $cantonField state).
     *
     * @var list<string>
     */
    public const array ATTRIBUTES = [
        'commune_id', 'marital_status', 'number_of_children', 'residence_permit',
        'pillar3a_amount', 'has_pillar2', 'kirchensteuer', 'other_income', 'birth_year',
    ];

    /**
     * @return array<Section>
     */
    public static function components(string $cantonField = 'tax_canton_id', bool $cantonRequired = true): array
    {
        return [
            Section::make('Tax residence')
                ->description('Defaults to your home address. Change it only if you\'re taxed somewhere else.')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    CantonSelect::make($cantonField)
                        ->label('Canton of residence')
                        ->required($cantonRequired),
                    ProfileFields::commune(cantonField: $cantonField, requiredWhenAvailable: $cantonRequired),
                    ProfileFields::residenceStatus(),
                    ProfileFields::quellensteuerWarning(),
                ]),
            Section::make('Household')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('marital_status')
                        ->label('Marital status')
                        ->options(MaritalStatus::class)
                        ->default(MaritalStatus::Single->value)
                        ->selectablePlaceholder(false)
                        ->required(),
                    ProfileFields::numberOfChildren(),
                    TextInput::make('birth_year')
                        ->label('Year of birth')
                        ->integer()
                        ->minValue(1900)
                        ->maxValue((int) date('Y'))
                        ->hint('Used for the AHV exemption from age 65')
                        ->tap(new ValidatesOnBlur),
                    ProfileFields::kirchensteuer(),
                ]),
            Section::make('Pension')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    ProfileFields::hasPillar2(),
                    ProfileFields::pillar3aAmount(),
                ]),
            Section::make('Other income')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('other_income')
                        ->label('Other income')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('CHF')
                        ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Yearly income outside your businesses, e.g. salary or rent')
                        ->tap(new ValidatesOnBlur),
                ]),
            Section::make('Income from your businesses')
                ->description('Year to date, from your invoices and confirmed expenses.')
                ->schema([
                    TextEntry::make('business_income')
                        ->hiddenLabel()
                        ->html()
                        ->state(fn (): HtmlString => self::businessIncomeTable()),
                ]),
        ];
    }

    /**
     * Canton options labelled "ZH — Zurich".
     *
     * @return array<string, string>
     */
    public static function cantonOptions(): array
    {
        return CantonSelect::options();
    }

    /**
     * The sole-proprietorship workspaces of the signed-in owner with their
     * revenue (excl. VAT), deductible expenses and profit, plus a total row.
     */
    public static function businessIncomeTable(): HtmlString
    {
        $user = Filament::auth()->user();
        $businesses = $user instanceof User
            ? $user->soleProprietorships()->orderBy('name')->get(['id', 'name'])
            : collect();

        $note = '<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Salaries and dividends from your GmbH/AG will appear here once those business types are supported.</p>';

        if ($businesses->isEmpty()) {
            return new HtmlString('<p class="text-sm text-gray-500 dark:text-gray-400">You have no sole proprietorship yet.</p>'.$note);
        }

        $metrics = app(BusinessMetrics::class)->forEntityIds($businesses->modelKeys());
        $money = fn (string $value): string => 'CHF '.number_format((float) $value, 2, '.', "'");
        $cell = 'px-3 py-2 text-right tabular-nums';

        $rows = $businesses->map(function (BusinessEntity $business) use ($metrics, $money, $cell): string {
            $figures = $metrics->get($business->getKey());

            return '<tr class="border-t border-gray-100 dark:border-white/5">'
                .'<td class="px-3 py-2">'.e($business->name).'</td>'
                ."<td class=\"{$cell}\">".$money($figures->revenueNet).'</td>'
                ."<td class=\"{$cell}\">".$money($figures->deductibleExpenses).'</td>'
                ."<td class=\"{$cell}\">".$money($figures->profit).'</td>'
                .'</tr>';
        })->implode('');

        $total = $metrics->reduce(
            fn (BusinessMetricsResult $sum, BusinessMetricsResult $figures): BusinessMetricsResult => $sum->plus($figures),
            new BusinessMetricsResult,
        );

        return new HtmlString(
            '<table class="w-full text-sm">'
            .'<thead><tr class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">'
            .'<th class="px-3 py-2 text-left font-medium">Business</th>'
            .'<th class="px-3 py-2 text-right font-medium">Revenue (excl. VAT)</th>'
            .'<th class="px-3 py-2 text-right font-medium">Deductible expenses</th>'
            .'<th class="px-3 py-2 text-right font-medium">Profit</th>'
            .'</tr></thead><tbody>'.$rows
            .'<tr class="border-t-2 border-gray-200 font-semibold dark:border-white/10">'
            .'<td class="px-3 py-2">Total</td>'
            ."<td class=\"{$cell}\">".$money($total->revenueNet).'</td>'
            ."<td class=\"{$cell}\">".$money($total->deductibleExpenses).'</td>'
            ."<td class=\"{$cell}\">".$money($total->profit).'</td>'
            .'</tr></tbody></table>'.$note
        );
    }
}
