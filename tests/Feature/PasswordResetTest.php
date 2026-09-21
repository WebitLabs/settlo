<?php

use App\Filament\Shared\Auth\RequestPasswordReset;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * The reset form is public and unauthenticated, so its answer must never reveal
 * whether an address has an account. Every outcome renders the same neutral
 * confirmation; only a real account is ever mailed a link.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    RateLimiter::clear('livewire-rate-limiter:'.sha1(RequestPasswordReset::class.'|request|127.0.0.1'));
    Notification::fake();
});

it('answers an unknown address exactly like a known one', function () {
    $owner = User::factory()->create(['email' => 'known@example.ch']);

    $known = Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $owner->email])
        ->call('request')
        ->assertHasNoFormErrors();

    RateLimiter::clear('livewire-rate-limiter:'.sha1(RequestPasswordReset::class.'|request|127.0.0.1'));

    $unknown = Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.ch'])
        ->call('request')
        ->assertHasNoFormErrors();

    // Same notification either way: no "we can't find a user" oracle.
    expect($unknown->effects['dispatches'] ?? [])->toEqual($known->effects['dispatches'] ?? []);
});

it('mails a link to a real account only', function () {
    $owner = User::factory()->create(['email' => 'known@example.ch']);

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $owner->email])
        ->call('request');

    Notification::assertSentTo($owner, ResetPassword::class);

    RateLimiter::clear('livewire-rate-limiter:'.sha1(RequestPasswordReset::class.'|request|127.0.0.1'));

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'nobody@example.ch'])
        ->call('request');

    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('throttles repeated requests from the same client', function () {
    // Distinct addresses, because the password broker separately refuses to
    // re-send to the same account within a minute; here we are proving the
    // per-client limit, not the broker's.
    $owners = collect(range(1, 3))->map(
        fn (int $n): User => User::factory()->create(['email' => "owner{$n}@example.ch"])
    );

    foreach ($owners as $owner) {
        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $owner->email])
            ->call('request');
    }

    // Two attempts per window: the third never reaches the broker.
    Notification::assertSentTimes(ResetPassword::class, 2);
    Notification::assertNotSentTo($owners->last(), ResetPassword::class);
});
