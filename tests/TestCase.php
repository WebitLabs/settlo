<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests never reach external APIs: every outgoing request must be faked.
     * Local servers (e.g. the Inertia SSR endpoint of a running Vite dev
     * server) stay reachable.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://localhost:*', 'http://127.0.0.1:*', 'http://[::1]:*']);
    }
}
