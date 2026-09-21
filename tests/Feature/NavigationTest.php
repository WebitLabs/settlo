<?php

use App\Filament\Personal\Pages\Billing;
use Database\Seeders\ReferenceDataSeeder;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    [$this->owner, $this->entity] = workspaceOwner('pro');
});

it('groups the personal area navigation', function () {
    $this->actingAs($this->owner)
        ->get('/app')
        ->assertOk()
        ->assertSeeInOrder([
            'Overview', 'Dashboard',
            'Businesses', 'My businesses', 'Set up a business',
            'Personal', 'Profile', 'Tax profile', 'Personal tax',
            'Account', 'Billing',
        ]);
});

it('groups the workspace navigation and links back to the personal area', function () {
    $this->actingAs($this->owner)
        ->get("/app/w/{$this->entity->getKey()}")
        ->assertOk()
        ->assertSeeInOrder([
            'Overview', 'All businesses', 'Dashboard',
            'Finance', 'Invoices', 'Expenses', 'Clients', 'Bank accounts',
            'Insights', 'Tax estimate', 'VAT summary',
            'Support', 'Ask Settlo',
            'Settings', 'Business settings',
        ])
        ->assertSee('Set up another business')
        ->assertSee(Billing::getUrl(['workspace' => $this->entity->getKey()], panel: 'app'), false)
        ->assertSee('Personal profile');
});
