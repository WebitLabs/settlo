<?php

use App\Http\Controllers\TestMailController;

it('sends a test email with the right token', function () {
    config(['mail.default' => 'array']);

    $this->getJson('/test-mail?token='.TestMailController::TOKEN)
        ->assertSuccessful()
        ->assertJson(['sent' => true, 'to' => TestMailController::RECIPIENT]);

    $messages = app('mailer')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getTo()[0]->getAddress())->toBe(TestMailController::RECIPIENT);
});

it('refuses a wrong or missing token', function (string $query) {
    config(['mail.default' => 'array']);

    $this->getJson('/test-mail'.$query)->assertForbidden();

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
})->with(['missing' => [''], 'wrong' => ['?token=wrong']]);
