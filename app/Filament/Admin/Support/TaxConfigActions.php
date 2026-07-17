<?php

namespace App\Filament\Admin\Support;

use App\Services\Tax\EffectiveDatedConfigWriter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Factory for the shared "New version" table row action used by every
 * effective-dated tax-configuration resource. The action is offered only on the
 * currently open, in-force row (open effective_to and a start date in the past);
 * future or same-day rows are edited directly instead. Submitting supersedes the
 * open row through the {@see EffectiveDatedConfigWriter}.
 */
class TaxConfigActions
{
    /**
     * @param  array<int, Component>  $schema  The value fields plus the year / effective_from inputs.
     */
    public static function newVersion(array $schema, string $table): Action
    {
        return Action::make('newVersion')
            ->label('New version')
            ->icon('heroicon-m-document-duplicate')
            ->color('primary')
            ->modalHeading('Create a new effective-dated version')
            ->modalDescription('The current period is closed the day before the new one begins. Past periods are never altered.')
            ->visible(fn (Model $record): bool => $record->effective_to === null
                && $record->effective_from->startOfDay()->lessThan(now()->startOfDay()))
            ->fillForm(fn (Model $record): array => self::prefill($record))
            ->schema($schema)
            ->action(function (array $data, Model $record) use ($table): void {
                app(EffectiveDatedConfigWriter::class)->newVersion($record, $data, $table);

                Notification::make()->title('New version created')->success()->send();
            });
    }

    /**
     * Seed the new-version form from the current row, advanced by one fiscal
     * year and starting on 1 January of that year.
     *
     * @return array<string, mixed>
     */
    private static function prefill(Model $record): array
    {
        /** @var array<string, mixed> $data */
        $data = $record->only($record->getFillable());

        $nextYear = ((int) $record->year) + 1;

        $data['year'] = $nextYear;
        $data['effective_from'] = Carbon::create($nextYear, 1, 1)->toDateString();

        unset($data['effective_to']);

        return $data;
    }
}
