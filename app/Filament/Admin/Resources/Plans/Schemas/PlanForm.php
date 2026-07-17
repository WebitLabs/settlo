<?php

namespace App\Filament\Admin\Resources\Plans\Schemas;

use App\Enums\PlanFeature;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(50)
                            // The code is the immutable contract keyed off by billing
                            // and feature checks, so it is locked once the plan exists.
                            ->disabled(fn (?string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (?string $operation): bool => $operation !== 'edit')
                            ->unique(table: 'plans', column: 'code', ignoreRecord: true)
                            ->helperText('Immutable after creation.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                    ]),

                Section::make('Commercials')
                    ->columns(3)
                    ->schema([
                        TextInput::make('price_monthly')
                            ->label('Monthly price')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix('CHF'),
                        TextInput::make('trial_days')
                            ->label('Trial days')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365),
                        TextInput::make('human_answers_quota')
                            ->label('Human answers / month')
                            ->required()
                            ->integer()
                            ->minValue(0),
                        TextInput::make('sort_order')
                            ->label('Sort order')
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                Section::make('Features')
                    ->columns(2)
                    ->schema([
                        CheckboxList::make('features')
                            ->label('Gated feature flags')
                            ->options(PlanFeature::class)
                            ->columnSpanFull(),
                        TagsInput::make('marketing_features')
                            ->label('Marketing bullet points')
                            ->placeholder('Add a selling point')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
