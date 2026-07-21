<?php

it('runs a whitelisted command with a valid bearer token', function () {
    config(['cron.secret' => 'test-secret']);

    $response = $this->withHeader('Authorization', 'Bearer test-secret')
        ->getJson('/cron/expire-trials');

    $response->assertSuccessful()
        ->assertJsonStructure(['command', 'exit_code', 'duration_ms'])
        ->assertJson([
            'command' => 'settlo:expire-trials',
            'exit_code' => 0,
        ]);
});

it('accepts the token as a query parameter fallback', function () {
    config(['cron.secret' => 'test-secret']);

    $this->getJson('/cron/reset-quotas?token=test-secret')
        ->assertSuccessful()
        ->assertJson(['command' => 'settlo:reset-quotas', 'exit_code' => 0]);
});

it('rejects an invalid token', function () {
    config(['cron.secret' => 'test-secret']);

    $this->withHeader('Authorization', 'Bearer wrong-secret')
        ->getJson('/cron/expire-trials')
        ->assertForbidden();
});

it('rejects a request with no token at all', function () {
    config(['cron.secret' => 'test-secret']);

    $this->getJson('/cron/expire-trials')->assertForbidden();
});

it('responds service unavailable when no cron secret is configured', function () {
    config(['cron.secret' => null]);

    $this->withHeader('Authorization', 'Bearer anything')
        ->getJson('/cron/expire-trials')
        ->assertServiceUnavailable();
});

it('returns not found for a command outside the whitelist', function () {
    config(['cron.secret' => 'test-secret']);

    $this->withHeader('Authorization', 'Bearer test-secret')
        ->getJson('/cron/horizon-snapshot')
        ->assertNotFound();
});
