<?php

namespace App\Filament\Workspace\Widgets;

use App\Filament\Workspace\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use App\Rules\ValidIban;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The active business' bank accounts with masked IBANs.
 */
class BankAccountsWidget extends TableWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Bank accounts')
            ->query(fn () => BankAccount::query()
                ->where('business_entity_id', Filament::getTenant()?->getKey())
                ->orderByDesc('is_default')
                ->orderBy('account_name'))
            ->paginated(false)
            ->headerActions([
                Action::make('manage')
                    ->label('Manage')
                    ->link()
                    ->url(fn (): string => BankAccountResource::getUrl('index')),
            ])
            ->emptyStateHeading('No bank accounts yet')
            ->columns([
                TextColumn::make('account_name')
                    ->label('Label')
                    ->weight('medium')
                    ->description(fn (BankAccount $record): ?string => $record->bank_name),
                TextColumn::make('iban')
                    ->label('IBAN')
                    ->formatStateUsing(fn (?string $state): string => self::maskIban((string) $state))
                    ->fontFamily('mono'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray'),
            ]);
    }

    /**
     * Show only the country/check digits and the last characters of an IBAN,
     * e.g. "CH93 •••• •••• 5295 7".
     */
    public static function maskIban(string $iban): string
    {
        $groups = str_split(ValidIban::normalize($iban), 4);

        if (count($groups) < 3) {
            return implode(' ', $groups);
        }

        $last = array_pop($groups);
        $tail = strlen($last) < 4 ? array_pop($groups).' '.$last : $last;

        return $groups[0].' •••• •••• '.$tail;
    }
}
