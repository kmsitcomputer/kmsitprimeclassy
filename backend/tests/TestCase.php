<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        TestDatabaseGuard::assertApplicationSafe($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The API uses Sanctum's SPA cookie-session mode (see bootstrap/app.php),
        // which only attaches session middleware to requests recognised as
        // "stateful" — normally decided from the browser's Referer/Origin
        // header. Test requests send neither by default, so every test that
        // touches an authenticated or session-backed route needs one.
        $this->withHeader('Referer', config('app.url'));
    }
}
