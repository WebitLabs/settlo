<?php

namespace App\Filament\Admin\Resources\KnowledgeBaseEntries\Schemas;

use App\Enums\AiQuestionCategory;
use App\Enums\VatStatus;
use App\Models\Canton;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KnowledgeBaseEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Entry')
                    ->columns(2)
                    ->schema([
                        Textarea::make('question')
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('answer')
                            ->required()
                            ->rows(8)
                            ->columnSpanFull(),
                        Select::make('category')
                            ->options(AiQuestionCategory::class)
                            ->required(),
                        Select::make('vat_status')
                            ->label('VAT status')
                            ->options(VatStatus::class)
                            ->placeholder('Any'),
                        Select::make('canton_code')
                            ->label('Canton')
                            ->options(fn (): array => Canton::query()->orderBy('code')->pluck('code', 'code')->all())
                            ->searchable()
                            ->placeholder('All cantons'),
                        TagsInput::make('tags')
                            ->placeholder('Add tag'),
                    ]),
            ]);
    }
}
