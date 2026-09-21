<?php

/**
 * The baseline browser security headers are added globally, so they cover the
 * Filament panels (which declare their own middleware stacks) as well as the
 * web group.
 */
it('sets the baseline security headers on every response', function () {
    $this->get('/up')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
});

it('does not send HSTS over plain http', function () {
    $this->get('http://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS over https', function () {
    $this->get('https://localhost/up')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('suppresses HSTS when the max age is zero', function () {
    config(['settlo.security_headers.hsts_max_age' => 0]);

    $this->get('https://localhost/up')->assertHeaderMissing('Strict-Transport-Security');
});

it('locks framing, base-uri, form-action and plugins down in the content security policy', function () {
    $policy = $this->get('/up')->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain("frame-ancestors 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        ->toContain("object-src 'none'")
        // Filament, Livewire and Alpine need these; the policy must not pretend otherwise.
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'");
});

it('can drop the content security policy without losing the other headers', function () {
    config(['settlo.security_headers.csp_enabled' => false]);

    $this->get('/up')
        ->assertHeaderMissing('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('covers the filament panel responses too', function () {
    $this->get('/admin/login')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});
