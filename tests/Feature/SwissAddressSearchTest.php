<?php

use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Shared\Fields\SwissAddressFields;
use App\Filament\Workspace\Resources\Clients\Pages\CreateClient;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Geo\AddressSuggestion;
use App\Services\Geo\SwissAddressSearch;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeAddressSearch(): void
{
    Http::fake(['api3.geo.admin.ch/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/geoadmin_search.json')))]);
}

describe('service', function () {
    it('parses the GeoAdmin results', function () {
        fakeAddressSearch();

        $suggestions = app(SwissAddressSearch::class)->search('Bahnhofstrasse 10');

        expect($suggestions)->toHaveCount(3)
            ->and($suggestions[1])->toEqual(new AddressSuggestion(
                id: '522931_0',
                street: 'Bahnhofstrasse',
                streetNumber: '10',
                postalCode: '5000',
                city: 'Aarau',
                cantonCode: 'AG',
                bfsNumber: '4001',
                label: 'Bahnhofstrasse 10 5000 Aarau',
            ))
            ->and($suggestions[2])
            ->street->toBe('Rue du Rhône')
            ->streetNumber->toBe('12a')
            ->city->toBe('Genève')
            ->cantonCode->toBe('GE')
            ->bfsNumber->toBe('6621');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api3.geo.admin.ch/rest/services/api/SearchServer')
            && $request['searchText'] === 'Bahnhofstrasse 10'
            && $request['origins'] === 'address'
            && $request['type'] === 'locations'
            && (int) $request['sr'] === 4326);
    });

    it('caches searches and remembers each suggestion by id', function () {
        fakeAddressSearch();
        $search = app(SwissAddressSearch::class);

        $search->search('Bahnhofstrasse 10');
        $search->search('Bahnhofstrasse 10');

        Http::assertSentCount(1);
        expect($search->find('167002_0'))->city->toBe('Zürich')->cantonCode->toBe('ZH')->bfsNumber->toBe('261')
            ->and($search->find('unknown'))->toBeNull();
    });

    it('parses a street without a house number', function () {
        $suggestion = app(SwissAddressSearch::class)->parse(['attrs' => [
            'label' => 'Seestrasse <b>8800 Thalwil</b>',
            'detail' => 'seestrasse 8800 thalwil 141 thalwil ch zh',
            'featureId' => '1_0',
        ]]);

        expect($suggestion)->street->toBe('Seestrasse')->streetNumber->toBeNull()->postalCode->toBe('8800')->city->toBe('Thalwil');
    });

    it('does not call the service for queries shorter than 3 characters', function () {
        fakeAddressSearch();

        expect(app(SwissAddressSearch::class)->search('Ba'))->toBe([]);

        Http::assertNothingSent();
    });

    it('returns no suggestions when the service fails', function () {
        Http::fake(['api3.geo.admin.ch/*' => Http::response('Server error', 500)]);

        expect(app(SwissAddressSearch::class)->search('Bahnhofstrasse'))->toBe([]);
    });

    it('returns no suggestions when the service is unreachable', function () {
        Http::fake(['api3.geo.admin.ch/*' => Http::failedConnection()]);

        expect(app(SwissAddressSearch::class)->search('Bahnhofstrasse'))->toBe([]);
    });
});

describe('address fields', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        $this->owner = User::factory()->owner()->create();
        $this->owner->forceFill(['phone_verified_at' => now()])->save();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->aargau = Canton::where('code', 'AG')->firstOrFail();
        $this->zurich = Canton::where('code', 'ZH')->firstOrFail();
    });

    it('offers the search results as options', function () {
        fakeAddressSearch();

        Livewire::test(PersonalProfile::class)
            ->assertSchemaComponentExists('address_search', 'addressForm', checkComponentUsing: fn (Select $field): bool => $field->getSearchResults('Bahnhofstrasse 10') === [
                '167002_0' => 'Dammstrasse 1 8037 Zürich',
                '522931_0' => 'Bahnhofstrasse 10 5000 Aarau',
                '2037304_6' => 'Rue du Rhône 12a 1204 Genève',
            ]);
    });

    it('fills the personal address, canton and commune from a selected suggestion', function () {
        fakeAddressSearch();
        app(SwissAddressSearch::class)->search('Bahnhofstrasse 10');

        Livewire::test(PersonalProfile::class)
            ->set('addressData.address_search', '522931_0')
            ->assertSet('addressData.street', 'Bahnhofstrasse')
            ->assertSet('addressData.street_number', '10')
            ->assertSet('addressData.postal_code', '5000')
            ->assertSet('addressData.city', 'Aarau')
            ->assertSet('addressData.canton_id', $this->aargau->getKey())
            ->assertSet('addressData.commune_id', Commune::where('canton_id', $this->aargau->getKey())->where('bfs_number', '4001')->value('id'))
            ->call('saveAddress')
            ->assertHasNoFormErrors(form: 'addressForm');

        expect($this->owner->fresh())
            ->street->toBe('Bahnhofstrasse')
            ->city->toBe('Aarau')
            ->canton_id->toBe($this->aargau->getKey());
    });

    it('fills the business address without a commune in the set-up stepper', function () {
        fakeAddressSearch();
        app(SwissAddressSearch::class)->search('Dammstrasse 1');

        Livewire::test(SetUpBusiness::class)
            ->set('businessData.address_search', '167002_0')
            ->assertSet('businessData.street', 'Dammstrasse')
            ->assertSet('businessData.street_number', '1')
            ->assertSet('businessData.postal_code', '8037')
            ->assertSet('businessData.city', 'Zürich')
            ->assertSet('businessData.canton_id', $this->zurich->getKey())
            ->assertSchemaComponentDoesNotExist('commune_id', 'businessForm');
    });

    it('ignores an unknown suggestion', function () {
        Livewire::test(PersonalProfile::class)
            ->set('addressData.street', 'Manual')
            ->set('addressData.address_search', 'unknown')
            ->assertSet('addressData.street', 'Manual');
    });

    it('fills city, canton and commune from the postal code', function () {
        Livewire::test(PersonalProfile::class)
            ->set('addressData.city', '')
            ->set('addressData.canton_id', null)
            ->set('addressData.postal_code', '5000')
            ->assertSet('addressData.city', 'Aarau')
            ->assertSet('addressData.canton_id', $this->aargau->getKey())
            ->assertSet('addressData.commune_id', Commune::where('canton_id', $this->aargau->getKey())->where('bfs_number', '4001')->value('id'));
    });

    it('fills the plain locality without the district number', function () {
        Livewire::test(PersonalProfile::class)
            ->set('addressData.city', '')
            ->set('addressData.canton_id', null)
            ->set('addressData.postal_code', '1000')
            ->assertSet('addressData.city', 'Lausanne')
            ->assertSet('addressData.canton_id', Canton::where('code', 'VD')->value('id'));
    });

    it('fills city and canton when typing 8001 in the set-up stepper', function () {
        Livewire::test(SetUpBusiness::class)
            ->set('businessData.city', '')
            ->set('businessData.canton_id', null)
            ->set('businessData.postal_code', '8001')
            ->assertSet('businessData.city', 'Zürich')
            ->assertSet('businessData.canton_id', $this->zurich->getKey());
    });

    it('replaces values it filled itself but never a city typed by hand', function () {
        Livewire::test(PersonalProfile::class)
            ->set('addressData.city', '')
            ->set('addressData.canton_id', null)
            ->set('addressData.postal_code', '8001')
            ->assertSet('addressData.city', 'Zürich')
            ->set('addressData.postal_code', '5000')
            ->assertSet('addressData.city', 'Aarau')
            ->assertSet('addressData.canton_id', $this->aargau->getKey())
            ->set('addressData.city', 'Aarau Rohr')
            ->set('addressData.postal_code', '5004')
            ->assertSet('addressData.city', 'Aarau Rohr');
    });

    it('keeps a manually entered city and canton', function () {
        Livewire::test(PersonalProfile::class)
            ->set('addressData.city', 'Buchs')
            ->set('addressData.canton_id', $this->zurich->getKey())
            ->set('addressData.postal_code', '5000')
            ->assertSet('addressData.city', 'Buchs')
            ->assertSet('addressData.canton_id', $this->zurich->getKey());
    });

    it('ignores invalid or unknown postal codes', function () {
        Livewire::test(PersonalProfile::class)
            ->set('addressData.city', '')
            ->set('addressData.postal_code', '0999')
            ->assertSet('addressData.city', '')
            ->set('addressData.postal_code', '1001')
            ->assertSet('addressData.city', '');
    });
});

it('strips the district number from a locality', function (string $locality, string $plain) {
    expect(SwissAddressFields::plainLocality($locality))->toBe($plain);
})->with([
    ['Lausanne 25', 'Lausanne'],
    ['Laax GR 2', 'Laax GR'],
    ['Zürich', 'Zürich'],
    ['St. Gallen', 'St. Gallen'],
    ['  Bern  ', 'Bern'],
]);

describe('client form', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        $this->owner = User::factory()->owner()->create();
        $this->entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();
        Subscription::factory()->forEntity($this->entity)->create();

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('workspace'));
        Filament::setTenant($this->entity);
    });

    it('fills a Swiss client address from a suggestion, without a canton', function () {
        fakeAddressSearch();
        app(SwissAddressSearch::class)->search('Bahnhofstrasse 10');

        Livewire::test(CreateClient::class)
            ->set('data.address_search', '522931_0')
            ->assertSet('data.street', 'Bahnhofstrasse')
            ->assertSet('data.postal_code', '5000')
            ->assertSet('data.city', 'Aarau')
            ->assertSchemaComponentDoesNotExist('canton_id');
    });

    it('autofills the city only for Swiss clients and hides the search for foreign ones', function () {
        Livewire::test(CreateClient::class)
            ->set('data.postal_code', '8001')
            ->assertSet('data.city', 'Zürich')
            ->set('data.country_code', 'DE')
            ->set('data.city', '')
            ->set('data.postal_code', '5000')
            ->assertSet('data.city', '')
            ->assertSchemaComponentHidden('address_search');
    });

    it('keeps the client address optional', function () {
        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Acme AG', 'country_code' => 'CH'])
            ->call('create')
            ->assertHasNoFormErrors();
    });
});
