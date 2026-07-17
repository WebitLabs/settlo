<?php

namespace App\Filament\Firm\Resources\Members\Pages;

use App\Enums\UserRole;
use App\Filament\Firm\Resources\Members\MemberResource;
use App\Models\AccountingFirmMember;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addMember')
                ->label('Add team member')
                ->icon('heroicon-m-user-plus')
                ->visible(fn (): bool => MemberResource::currentUserIsFirmOwner())
                ->schema([
                    TextInput::make('email')
                        ->label('Accountant email')
                        ->helperText('The person must already have a Settlo accountant account.')
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->addMember(Str::lower($data['email']));
                }),
        ];
    }

    /**
     * Attach an existing accountant to the firm. Only accountant-role users may
     * be added, and never twice. Firm-owner gate is re-checked server-side.
     */
    private function addMember(string $email): void
    {
        if (! MemberResource::currentUserIsFirmOwner()) {
            Notification::make()->title('Only firm owners can add members')->danger()->send();

            return;
        }

        $tenant = Filament::getTenant();

        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->role !== UserRole::Accountant) {
            Notification::make()
                ->title('No accountant account found')
                ->body('There is no Settlo accountant registered with that email.')
                ->danger()
                ->send();

            return;
        }

        $alreadyMember = AccountingFirmMember::query()
            ->where('accounting_firm_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if ($alreadyMember) {
            Notification::make()->title('Already a member')->warning()->send();

            return;
        }

        AccountingFirmMember::query()->create([
            'accounting_firm_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'is_owner' => false,
            'joined_at' => Carbon::now(),
        ]);

        Notification::make()->title('Team member added')->success()->send();
    }
}
