<?php

use App\Enums\BusinessEntityType;
use App\Enums\VatStatus;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Models\Canton;
use App\Models\User;
use App\Services\Registry\UidRecord;
use App\Services\Registry\UidRegister;
use App\Services\Registry\UidRegisterUnavailable;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function uidRegisterFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/uid_register/{$name}.xml"));
}

function fakeUidRegister(string $fixture, int $status = 200): void
{
    Http::fake(['www.uid-wse.admin.ch/*' => Http::response(uidRegisterFixture($fixture), $status, ['Content-Type' => 'text/xml; charset=utf-8'])]);
}

describe('service', function () {
    it('looks an organisation up with a SOAP GetByUID request', function () {
        fakeUidRegister('get_by_uid');

        $record = app(UidRegister::class)->lookup('CHE-105.829.940');

        expect($record)->toBeInstanceOf(UidRecord::class)
            ->uid->toBe('CHE-105.829.940')
            ->name->toBe('Migros-Genossenschafts-Bund')
            ->legalName->toBe('Migros-Genossenschafts-Bund')
            ->legalForm->toBe('0108')
            ->street->toBe('Limmatstrasse')
            ->houseNumber->toBe('152')
            ->postalCode->toBe('8005')
            ->town->toBe('Zürich')
            ->bfsNumber->toBe('261')
            ->cantonCode->toBe('ZH')
            ->vatStatus->toBe('2')
            ->vatEntryStatus->toBe('1')
            ->vatUid->toBe('CHE-105.829.940')
            ->and($record->businessType())->toBeNull()
            ->and($record->isVatActive())->toBeTrue()
            ->and($record->vatNumber())->toBe('CHE-105.829.940 MWST');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.uid-wse.admin.ch/V5.0/PublicServices.svc'
            && $request->method() === 'POST'
            && $request->hasHeader('SOAPAction', '"http://www.uid.admin.ch/xmlns/uid-wse/IPublicServices/GetByUID"')
            && str_contains($request->header('Content-Type')[0], 'text/xml')
            && str_contains($request->body(), '<ns:uidOrganisationId>105829940</ns:uidOrganisationId>'));
    });

    it('maps legal forms to business types', function (?string $legalForm, ?BusinessEntityType $type) {
        expect((new UidRecord(uid: 'CHE-105.829.940', name: 'X', legalForm: $legalForm))->businessType())->toBe($type);
    })->with([
        ['0101', BusinessEntityType::SoleProprietorship],
        ['0106', BusinessEntityType::AG],
        ['0107', BusinessEntityType::GmbH],
        ['0108', null],
        [null, null],
    ]);

    it('has no VAT number without an active VAT registration', function () {
        expect((new UidRecord(uid: 'CHE-105.829.940', name: 'X'))->vatNumber())->toBeNull()
            ->and((new UidRecord(uid: 'CHE-105.829.940', name: 'X', vatStatus: '3', vatUid: 'CHE-105.829.940'))->vatNumber())->toBeNull()
            ->and((new UidRecord(uid: 'CHE-105.829.940', name: 'X', vatStatus: '2', vatUid: 'CHE-105.829.940', vatLiquidated: true))->vatNumber())->toBeNull()
            ->and((new UidRecord(uid: 'CHE-105.829.940', name: 'X', vatStatus: '2', vatUid: 'CHE-105.829.940', vatEntryStatus: '2'))->vatNumber())->toBeNull()
            ->and((new UidRecord(uid: 'CHE-105.829.940', name: 'X', vatStatus: '1', vatUid: 'CHE-105.829.940', vatEntryStatus: '1'))->vatNumber())->toBeNull()
            ->and((new UidRecord(uid: 'CHE-105.829.940', name: 'X', vatStatus: '2', vatUid: 'CHE-105.829.940', vatEntryStatus: '1'))->vatNumber())->toBe('CHE-105.829.940 MWST');
    });

    it('returns null for an unknown UID', function () {
        fakeUidRegister('not_found');

        expect(app(UidRegister::class)->lookup('CHE-148.830.302'))->toBeNull();
    });

    it('returns null for a SOAP client fault', function () {
        fakeUidRegister('fault', 500);

        expect(app(UidRegister::class)->lookup('CHE-148.830.302'))->toBeNull();
    });

    it('does not call the register for an invalid UID', function () {
        fakeUidRegister('get_by_uid');

        expect(app(UidRegister::class)->lookup('CHE-123.456.789'))->toBeNull();

        Http::assertNothingSent();
    });

    it('throws when the register is unavailable', function () {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response('Service Unavailable', 503)]);

        app(UidRegister::class)->lookup('CHE-105.829.940');
    })->throws(UidRegisterUnavailable::class);

    it('throws when the register is unreachable', function () {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::failedConnection()]);

        app(UidRegister::class)->lookup('CHE-105.829.940');
    })->throws(UidRegisterUnavailable::class);

    it('caches lookups per UID', function () {
        fakeUidRegister('get_by_uid');
        $register = app(UidRegister::class);

        $register->lookup('CHE-105.829.940');
        $record = $register->lookup('CHE105829940');

        Http::assertSentCount(1);
        expect($record->name)->toBe('Migros-Genossenschafts-Bund');
    });

    it('limits uncached lookups to 5 per minute per user', function () {
        fakeUidRegister('not_found');
        $register = app(UidRegister::class);

        foreach (['CHE-105.829.940', 'CHE-148.830.302', 'CHE-123.456.788', 'CHE-000.000.000', 'CHE-100.000.006'] as $uid) {
            $register->lookup($uid, 'user-1');
        }

        expect(fn () => $register->lookup('CHE-116.281.710', 'user-1'))
            ->toThrow(fn (UidRegisterUnavailable $exception) => expect($exception->isRateLimited())->toBeTrue());

        expect($register->lookup('CHE-116.281.710', 'user-2'))->toBeNull();
    });
});

describe('set-up stepper', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
    });

    it('fills the business profile once a valid UID is entered', function () {
        fakeUidRegister('sole_proprietorship');

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.uid', 'CHE-148.830.302')
            ->assertSet('businessData.name', 'Muster Design Anna Muster')
            ->assertSet('businessData.legal_name', 'Muster Design')
            ->assertSet('businessData.type', BusinessEntityType::SoleProprietorship->value)
            ->assertSet('businessData.street', 'Bahnhofstrasse')
            ->assertSet('businessData.street_number', '10')
            ->assertSet('businessData.postal_code', '5000')
            ->assertSet('businessData.city', 'Aarau')
            ->assertSet('businessData.canton_id', Canton::where('code', 'AG')->value('id'))
            ->assertSet('businessData.mwst_number', null)
            ->assertNotified('Filled from the UID register — please check the details.');
    });

    it('does not look up an incomplete or invalid UID', function () {
        fakeUidRegister('sole_proprietorship');

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.uid', 'CHE-148.830')
            ->set('businessData.uid', 'CHE-123.456.789')
            ->assertHasFormErrors(['uid'], 'businessForm');

        Http::assertNothingSent();
    });

    it('fills the VAT registration and warns about unsupported legal forms from the lookup action', function () {
        fakeUidRegister('get_by_uid');

        Livewire::test(SetUpBusiness::class)
            ->fillForm(['uid' => 'CHE-105.829.940'], 'businessForm')
            ->set('businessData.type', BusinessEntityType::SoleProprietorship->value)
            ->callAction(TestAction::make('lookupUid')->schemaComponent('uid', schema: 'businessForm'))
            ->assertSet('businessData.name', 'Migros-Genossenschafts-Bund')
            ->assertSet('businessData.type', BusinessEntityType::SoleProprietorship->value)
            ->assertSet('businessData.city', 'Zürich')
            ->assertSet('businessData.vat_status', VatStatus::RegisteredMandatory->value)
            ->assertSet('businessData.mwst_number', 'CHE-105.829.940 MWST')
            ->assertNotified('Settlo currently supports sole proprietorships only.');
    });

    it('warns that GmbH and AG are coming soon', function () {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response(str_replace('>0101<', '>0107<', uidRegisterFixture('sole_proprietorship')))]);

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.uid', 'CHE-148.830.302')
            ->assertSet('businessData.type', BusinessEntityType::SoleProprietorship->value)
            ->assertNotified('GmbH and AG are coming soon — you can continue as a sole proprietorship later.');
    });

    it('asks to fill in the details manually when the UID is unknown', function () {
        fakeUidRegister('not_found');

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.name', 'Kept')
            ->set('businessData.uid', 'CHE-148.830.302')
            ->assertSet('businessData.name', 'Kept')
            ->assertNotified("We couldn't find this UID. Please fill in the details manually.");
    });

    it('warns when the register is unavailable', function () {
        Http::fake(['www.uid-wse.admin.ch/*' => Http::response('Service Unavailable', 503)]);

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.uid', 'CHE-148.830.302')
            ->assertNotified("The UID register isn't reachable right now. Please fill in the details manually or try again later.");
    });

    it('warns when the lookup limit is reached', function () {
        fakeUidRegister('sole_proprietorship');

        foreach (range(1, 5) as $attempt) {
            RateLimiter::hit('uid-lookup:'.$this->owner->getKey());
        }

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.uid', 'CHE-148.830.302')
            ->assertNotified('Too many UID lookups. Please wait a minute and try again.');

        Http::assertNothingSent();
    });
});
