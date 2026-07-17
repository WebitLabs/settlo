<?php

namespace App\Filament\Admin\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime('d.m.Y H:i:s'),
                        TextEntry::make('action')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('actor_name')
                            ->label('Actor')
                            ->state(fn (AuditLog $record): ?string => $record->actor?->getFilamentName())
                            ->placeholder('System'),
                        TextEntry::make('impersonator_name')
                            ->label('Impersonator')
                            ->state(fn (AuditLog $record): ?string => $record->impersonator?->getFilamentName())
                            ->placeholder('—'),
                        TextEntry::make('subject')
                            ->label('Subject')
                            ->state(fn (AuditLog $record): string => $record->subject_type === null
                                ? '—'
                                : class_basename($record->subject_type).' #'.$record->subject_id),
                        TextEntry::make('ip_address')
                            ->label('IP address')
                            ->placeholder('—'),
                        TextEntry::make('user_agent')
                            ->label('User agent')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ]),
                Section::make('Properties')
                    ->schema([
                        TextEntry::make('properties')
                            ->hiddenLabel()
                            ->state(fn (AuditLog $record): string => filled($record->properties)
                                ? (string) json_encode($record->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : '—')
                            ->fontFamily('mono')
                            ->copyable(),
                    ])
                    ->visible(fn (AuditLog $record): bool => filled($record->properties)),
            ]);
    }
}
