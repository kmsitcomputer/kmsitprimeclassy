<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestDatabaseGuard;
use Tests\Support\TestRunLock;
use Tests\Support\TestRunReport;

abstract class TestCase extends BaseTestCase
{
    private float $reportStartedAt = 0.0;

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        // Guard configuration and acquire the schema lock BEFORE application providers can query.
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class, static function ($app): void {
            TestDatabaseGuard::assertRunHistorySafe(dirname(__DIR__));
            TestDatabaseGuard::assertApplicationSafe($app);
            TestDatabaseGuard::installConnectionGuards($app);
            TestRunLock::acquireForApplication($app);
        });
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        TestDatabaseGuard::assertApplicationSafe($app);

        // One runner per test database: refuse (fail-fast) before any schema work if another owns it.
        TestRunLock::acquireForApplication($app);

        return $app;
    }

    protected function setUp(): void
    {
        $this->reportStartedAt = microtime(true);
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

    protected function tearDown(): void
    {
        parent::tearDown();
        TestRunReport::testFinished(static::class, microtime(true) - $this->reportStartedAt);
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

    /**
     * Gudang's fulfilment submission now carries the handover evidence (required) and a reason for every
     * line handed over for less than its outstanding quantity. Fixtures that only care about the resulting
     * stock/assignment behaviour use this to send a valid submission; tests of the reason/evidence rules
     * post their own payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withHandoverProof(array $payload): array
    {
        foreach ($payload['items'] ?? [] as $i => $line) {
            $payload['items'][$i]['shortage_reason'] ??= 'uji: sisa menyusul pada batch berikutnya';
        }

        return $payload + ['proof' => \Illuminate\Http\UploadedFile::fake()->image('serah-terima.jpg', 40, 40)];
    }
}
