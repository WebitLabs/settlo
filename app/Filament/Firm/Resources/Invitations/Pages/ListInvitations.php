<?php

namespace App\Filament\Firm\Resources\Invitations\Pages;

use App\Filament\Firm\Resources\Invitations\InvitationResource;
use App\Services\Firm\FirmInvitationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite client')
                ->icon('heroicon-m-paper-airplane')
                ->schema([
                    TextInput::make('email')
                        ->label('Client email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Textarea::make('message')
                        ->label('Personal message')
                        ->helperText('Optional — included in the invitation email.')
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    app(FirmInvitationService::class)->invite(
                        Filament::getTenant(),
                        $data['email'],
                        Auth::user(),
                        filled($data['message'] ?? null) ? $data['message'] : null,
                    );

                    Notification::make()
                        ->title('Invitation sent')
                        ->body("An invitation email was sent to {$data['email']}.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
