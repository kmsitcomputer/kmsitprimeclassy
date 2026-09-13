<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        if (! in_array($connection, ['mysql', 'mariadb'], true)
            || ! is_string($database)
            || ! preg_match('/_test(?:ing)?(?:_|$)/', $database)) {
            throw new \RuntimeException('Tests require an isolated MySQL database whose name contains _test or _testing. Existing application databases must never be used.');
        }

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
