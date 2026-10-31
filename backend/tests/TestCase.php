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

        // New payment proofs are stored on the PRIVATE ('local') disk; never write test uploads into the real storage.
        \Illuminate\Support\Facades\Storage::fake('local');
    }

    /**
     * A deterministic FUTURE date for fixtures that must satisfy the application's
     * `after_or_equal:today` rules (order delivery date, item reschedule, report ranges).
     *
     * Historical hard-coded literals turn into calendar time bombs: the same suite started
     * failing the day the fixture date fell into the past. Offsets are relative to the day the
     * test runs, and the value is stable within a single test run, so assertions comparing the
     * stored date against the fixture date keep working.
     */
    protected function futureDate(int $daysFromToday): string
    {
        return \Illuminate\Support\Carbon::today()->addDays($daysFromToday)->toDateString();
    }
}
