<?php

use App\Filament\Personal\Auth\Register;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Filament\Workspace\Resources\Clients\Pages\CreateClient;
use App\Models\BusinessEntity;
use App\Models\Canton;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/**
 * Signs in an owner with an active subscription and a business, and makes it the tenant.
 */
function actAsOwnerWithTenant(): BusinessEntity
{
    [$owner, $entity] = workspaceOwner();
    $entity->forceFill(['iban' => 'CH9300762011623852957'])->save();

    actAsWorkspace($owner, $entity);

    return $entity;
}

describe('native browser validation (A1)', function () {
    it('disables it on the registration form', function () {
        $this->get('/app/register')
            ->assertOk()
            ->assertSee('novalidate', false);
    });

    it('disables it on resource forms', function () {
        actAsOwnerWithTenant();

        Livewire::test(CreateClient::class)
            ->assertSeeHtml('novalidate');
    });

    it('shows Filament\'s server-side message instead', function () {
        actAsOwnerWithTenant();

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required'])
            ->assertSee('The name field is required.');
    });
});

describe('acronyms in validation messages (A2)', function () {
    it('keeps "IBAN" capitalised', function () {
        actAsOwnerWithTenant();

        Livewire::test(BusinessSettings::class)
            ->fillForm(['iban' => ''], 'invoicingForm')
            ->call('saveInvoicing')
            ->assertHasFormErrors(['iban' => 'required'], 'invoicingForm')
            ->assertSee('The IBAN field is required.')
            ->assertDontSee('iBAN');
    });

    it('keeps "UID" capitalised', function () {
        actAsOwnerWithTenant();

        Livewire::test(BusinessSettings::class)
            ->fillForm(['uid' => 'CHE-12'], 'profileForm')
            ->call('saveProfile')
            ->assertHasFormErrors(['uid'], 'profileForm')
            ->assertSee('Enter a valid Swiss UID')
            ->assertDontSee('uID');
    });
});

describe('validate on blur (A3)', function () {
    it('validates the registration password as soon as it changes and clears the error once fixed', function () {
        Livewire::test(Register::class)
            ->set('data.password', 'short')
            ->assertHasErrors(['data.password'])
            ->set('data.password', 'long-enough-Passw0rd')
            ->assertHasNoErrors(['data.password']);
    });

    it('reports a password mismatch on the confirmation field only', function () {
        Livewire::test(Register::class)
            ->set('data.password', 'long-enough-Passw0rd')
            ->set('data.passwordConfirmation', 'something-else')
            ->assertHasNoErrors(['data.password'])
            ->assertHasErrors(['data.passwordConfirmation' => 'same']);
    });

    it('validates a client email as soon as it changes', function () {
        actAsOwnerWithTenant();

        Livewire::test(CreateClient::class)
            ->set('data.email', 'not-an-email')
            ->assertHasErrors(['data.email' => 'email'])
            ->set('data.email', 'billing@example.ch')
            ->assertHasNoErrors(['data.email']);
    });

    it('validates the IBAN in business settings without saving', function () {
        $entity = actAsOwnerWithTenant();

        Livewire::test(BusinessSettings::class)
            ->set('invoicingData.iban', 'CH00 0000 0000 0000 0000 0')
            ->assertHasErrors(['invoicingData.iban'])
            ->set('invoicingData.iban', 'CH93 0076 2011 6238 5295 7')
            ->assertHasNoErrors(['invoicingData.iban'])
            ->assertNotNotified();

        expect($entity->refresh()->iban)->toBe('CH9300762011623852957');
    });

    it('clears the canton "required" error in business settings as soon as a canton is picked', function () {
        actAsOwnerWithTenant();

        Livewire::test(BusinessSettings::class)
            ->fillForm(['canton_id' => null], 'profileForm')
            ->call('saveProfile')
            ->assertHasFormErrors(['canton_id' => 'required'], 'profileForm')
            ->set('profileData.canton_id', Canton::where('code', 'ZG')->value('id'))
            ->assertHasNoErrors(['profileData.canton_id']);
    });

    it('shows the canton "required" error as soon as the canton is cleared', function () {
        actAsOwnerWithTenant();

        Livewire::test(BusinessSettings::class)
            ->set('profileData.canton_id', null)
            ->assertHasErrors(['profileData.canton_id' => 'required']);
    });
});
