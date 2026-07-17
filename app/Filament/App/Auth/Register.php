<?php

namespace App\Filament\App\Auth;

use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * App-panel signup. Collects the fields Settlo needs on top of the framework
 * defaults (name split, phone, language) and — crucially — sets the security
 * critical role/status server-side via forceFill so they can never be forged
 * from the request payload.
 */
class Register extends BaseRegister
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('First name')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
                TextInput::make('last_name')
                    ->label('Last name')
                    ->required()
                    ->maxLength(255),
                $this->getEmailFormComponent(),
                TextInput::make('phone')
                    ->label('Phone number')
                    ->tel()
                    ->maxLength(50)
                    ->helperText('Used for account security and accountant contact.'),
                Select::make('preferred_language')
                    ->label('Language')
                    ->options(Language::class)
                    ->default('en')
                    ->selectablePlaceholder(false)
                    ->required(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    /**
     * Role and status are excluded from mass assignment; they are forced here so
     * a crafted registration cannot escalate a new account beyond an owner.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Model
    {
        $model = $this->getUserModel();

        /** @var Model $user */
        $user = new $model;
        $user->fill($data);
        $user->forceFill([
            'role' => UserRole::Owner,
            'status' => UserStatus::Active,
        ])->save();

        return $user;
    }
}
