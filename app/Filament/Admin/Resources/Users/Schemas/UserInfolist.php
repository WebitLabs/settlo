<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->state(fn (User $record): string => $record->getFilamentName()),
                        TextEntry::make('email')
                            ->copyable(),
                        TextEntry::make('role')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('phone')
                            ->placeholder('—'),
                        TextEntry::make('preferred_language')
                            ->label('Language'),
                        TextEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->dateTime('d.m.Y H:i')
                            ->placeholder('Not verified'),
                        TextEntry::make('created_at')
                            ->label('Joined')
                            ->dateTime('d.m.Y H:i'),
                    ]),
                Section::make('Footprint')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('ownedEntities_count')
                            ->label('Business entities')
                            ->state(fn (User $record): int => $record->ownedEntities()->count()),
                        TextEntry::make('firmMemberships_count')
                            ->label('Firm memberships')
                            ->state(fn (User $record): int => $record->firmMemberships()->count()),
                    ]),
            ]);
    }
}
