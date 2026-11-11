<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

class ConcurrencyHarness
{
    protected string $root;

    protected string $runtimeDir;

    protected string $runId;

    protected string $actorAReady;

    protected string $actorBAttempt;

    protected string $releaseFile;

    protected string $actorAResult;

    protected string $actorBResult;

    /**
     * Per-actor startup evidence for runServiceRace: spawn->ready seconds and how many launches it took.
     *
     * @var array<string, array{ready_seconds: float, attempts: int, first_attempt_stall: ?string}>
     */
    protected array $actorStartup = [];

    /** Readiness budget per launch for service-race actors (seconds); only the harness self-test shortens it. */
    protected int $readyTimeoutSeconds = 15;

    public function __construct()
    {
        $this->root = dirname(__DIR__, 2);
        $this->runId = 'concurrency-'.bin2hex(random_bytes(8));
        $this->runtimeDir = $this->root.'/storage/framework/testing/concurrency/'.$this->runId;
        $this->actorAReady = $this->runtimeDir.'/actor-a.ready';
        $this->actorBAttempt = $this->runtimeDir.'/actor-b.attempt';
        $this->releaseFile = $this->runtimeDir.'/release';
        $this->actorAResult = $this->runtimeDir.'/actor-a.result.json';
        $this->actorBResult = $this->runtimeDir.'/actor-b.result.json';
    }

    public function run(): array
    {
        $this->ensureRuntimeDir();

        $this->cleanupFiles([
            $this->actorAReady,
            $this->actorBAttempt,
            $this->releaseFile,
            $this->actorAResult,
            $this->actorBResult,
        ]);

        $aProcess = $this->spawnActor('actor-a');

        try {
            $this->waitForSignal($this->actorAReady, 15);

            $bProcess = $this->spawnActor('actor-b');
            $this->waitForSignal($this->actorBAttempt, 15);

            $aResult = $this->readJson($this->actorAResult);

            if (($aResult['locked'] ?? false) !== true) {
                throw new \RuntimeException('Actor A did not acquire the row lock before the release step.');
            }

            if (! file_exists($this->actorBAttempt)) {
                throw new \RuntimeException('Actor B did not begin the competing lock attempt before the release step.');
            }

            $this->writeRelease();

            $aProcess->wait();
            $bProcess->wait();

            $aResult = $this->readJson($this->actorAResult);
            $bResult = $this->readJson($this->actorBResult);

            if (($bResult['attempted'] ?? false) !== true) {
                throw new \RuntimeException('Actor B did not report a competing lock attempt after release.');
            }

            $result = [
                'different_connections' => (($aResult['connection_id'] ?? null) !== ($bResult['connection_id'] ?? null)),
                'a_locked' => ($aResult['locked'] ?? false) === true,
                'b_attempted' => ($bResult['attempted'] ?? false) === true,
                'b_blocked_while_a_owned_lock' => ($bResult['blocked_while_a_owned_lock'] ?? false) === true,
                'a_released' => ($aResult['released'] ?? false) === true,
                'b_completed_after_release' => ($bResult['completed_after_release'] ?? false) === true,
                'a_connection_id' => $aResult['connection_id'] ?? null,
                'b_connection_id' => $bResult['connection_id'] ?? null,
                'timeline' => [
                    'a' => $aResult['timeline'] ?? [],
                    'b' => $bResult['timeline'] ?? [],
                ],
            ];

            if (! $result['different_connections']) {
                throw new \RuntimeException('Actor A and Actor B did not use different MySQL connections.');
            }

            if (! $result['b_blocked_while_a_owned_lock'] || ! $result['b_completed_after_release']) {
                throw new \RuntimeException('The competing lock attempt did not exhibit true blocking and release behavior.');
            }

            $this->cleanupFiles([
                $this->actorAReady,
                $this->actorBAttempt,
                $this->releaseFile,
                $this->actorAResult,
                $this->actorBResult,
            ]);

            return $result;
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            $this->cleanupProcess($aProcess);
            $this->cleanupBarrierDir();
        }
    }

    public function runCheckout(array $configuration): array
    {
        $this->ensureRuntimeDir();
        $readyA = $this->runtimeDir.'/checkout-a.ready';
        $readyB = $this->runtimeDir.'/checkout-b.ready';
        $release = $this->runtimeDir.'/checkout.release';
        $resultA = $this->runtimeDir.'/checkout-a.result.json';
        $resultB = $this->runtimeDir.'/checkout-b.result.json';
        $paths = [$readyA, $readyB, $release, $resultA, $resultB];
        $this->cleanupFiles($paths);

        $aProcess = $this->spawnCheckoutActor('actor-a', $configuration, $readyA, $readyB, $release, $resultA, $resultB);

        try {
            $this->waitForSignal($readyA, 15);
            $bProcess = $this->spawnCheckoutActor('actor-b', $configuration, $readyA, $readyB, $release, $resultA, $resultB);
            $this->waitForSignal($readyB, 15);
            $this->writeFile($release, 'go');

            $aProcess->wait();
            $bProcess->wait();
            $aResult = $this->readJson($resultA);
            $bResult = $this->readJson($resultB);

            if (($aResult['connection_id'] ?? null) === ($bResult['connection_id'] ?? null)) {
                throw new \RuntimeException('Checkout actors did not use different MySQL connections.');
            }

            if (($aResult['started_at'] ?? 0) >= ($bResult['completed_at'] ?? 0)
                || ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
                throw new \RuntimeException('Checkout actors did not overlap in time.');
            }

            if (($aResult['exit_code'] ?? 1) === 0 && ($bResult['exit_code'] ?? 1) === 0) {
                throw new \RuntimeException('Both concurrent checkout actors committed successfully.');
            }

            return [
                'actor_a' => $aResult,
                'actor_b' => $bResult,
                'different_connections' => true,
                'true_overlap' => true,
            ];
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            $this->cleanupProcess($aProcess);
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    public function runFulfillment(array $configuration): array
    {
        $this->ensureRuntimeDir();
        $readyA = $this->runtimeDir.'/fulfillment-a.ready';
        $readyB = $this->runtimeDir.'/fulfillment-b.ready';
        $release = $this->runtimeDir.'/fulfillment.release';
        $resultA = $this->runtimeDir.'/fulfillment-a.result.json';
        $resultB = $this->runtimeDir.'/fulfillment-b.result.json';
        $paths = [$readyA, $readyB, $release, $resultA, $resultB];
        $this->cleanupFiles($paths);

        $aProcess = $this->spawnFulfillmentActor('actor-a', $configuration, $readyA, $readyB, $release, $resultA, $resultB);

        try {
            $this->waitForSignal($readyA, 15);
            $bProcess = $this->spawnFulfillmentActor('actor-b', $configuration, $readyA, $readyB, $release, $resultA, $resultB);
            $this->waitForSignal($readyB, 15);
            $this->writeFile($release, 'go');

            $aProcess->wait();
            $bProcess->wait();
            $aResult = $this->readJson($resultA);
            $bResult = $this->readJson($resultB);

            if (($aResult['connection_id'] ?? null) === ($bResult['connection_id'] ?? null)) {
                throw new \RuntimeException('Fulfillment actors did not use different MySQL connections.');
            }

            if (($aResult['started_at'] ?? 0) >= ($bResult['completed_at'] ?? 0)
                || ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
                throw new \RuntimeException('Fulfillment actors did not overlap in time.');
            }

            return [
                'actor_a' => $aResult,
                'actor_b' => $bResult,
                'different_connections' => true,
                'true_overlap' => true,
            ];
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            $this->cleanupProcess($aProcess);
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    public function runFulfillmentCancellation(array $configuration): array
    {
        $this->ensureRuntimeDir();
        $readyA = $this->runtimeDir.'/fc-a.ready';
        $readyB = $this->runtimeDir.'/fc-b.ready';
        $release = $this->runtimeDir.'/fc.release';
        $resultA = $this->runtimeDir.'/fc-a.result.json';
        $resultB = $this->runtimeDir.'/fc-b.result.json';
        $paths = [$readyA, $readyB, $release, $resultA, $resultB];
        $this->cleanupFiles($paths);

        $aProcess = $this->spawnFulfillmentCancellationActor('fulfillment', $configuration, $readyA, $readyB, $release, $resultA, $resultB);

        try {
            $this->waitForSignal($readyA, 15);
            $bProcess = $this->spawnFulfillmentCancellationActor('cancellation', $configuration, $readyA, $readyB, $release, $resultA, $resultB);
            $this->waitForSignal($readyB, 15);
            $this->writeFile($release, 'go');

            $aProcess->wait();
            $bProcess->wait();
            $aResult = $this->readJson($resultA);
            $bResult = $this->readJson($resultB);

            if (($aResult['connection_id'] ?? null) === ($bResult['connection_id'] ?? null)) {
                throw new \RuntimeException('Fulfillment and cancellation did not use different MySQL connections.');
            }

            if (($aResult['started_at'] ?? 0) >= ($bResult['completed_at'] ?? 0)
                || ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
                throw new \RuntimeException('Fulfillment and cancellation did not overlap in time.');
            }

            return [
                'fulfillment' => $aResult,
                'cancellation' => $bResult,
                'different_connections' => true,
                'true_overlap' => true,
            ];
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            $this->cleanupProcess($aProcess);
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    public function runTransferOpname(array $configuration): array
    {
        $this->ensureRuntimeDir();
        $readyA = $this->runtimeDir.'/to-a.ready';
        $readyB = $this->runtimeDir.'/to-b.ready';
        $release = $this->runtimeDir.'/to.release';
        $resultA = $this->runtimeDir.'/to-a.result.json';
        $resultB = $this->runtimeDir.'/to-b.result.json';
        $paths = [$readyA, $readyB, $release, $resultA, $resultB];
        $this->cleanupFiles($paths);

        $aProcess = $this->spawnTransferOpnameActor('transfer', $configuration, $readyA, $readyB, $release, $resultA, $resultB);

        try {
            $this->waitForSignal($readyA, 15);
            $bProcess = $this->spawnTransferOpnameActor('opname', $configuration, $readyA, $readyB, $release, $resultA, $resultB);
            $this->waitForSignal($readyB, 15);
            $this->writeFile($release, 'go');

            $aProcess->wait();
            $bProcess->wait();
            $aResult = $this->readJson($resultA);
            $bResult = $this->readJson($resultB);

            if (($aResult['connection_id'] ?? null) === ($bResult['connection_id'] ?? null)) {
                throw new \RuntimeException('Transfer and opname actors did not use different MySQL connections.');
            }

            if (($aResult['started_at'] ?? 0) >= ($bResult['completed_at'] ?? 0)
                || ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
                throw new \RuntimeException('Transfer and opname actors did not overlap in time.');
            }

            return [
                'transfer' => $aResult,
                'opname' => $bResult,
                'different_connections' => true,
                'true_overlap' => true,
            ];
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            $this->cleanupProcess($aProcess);
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    /**
     * Two service operations racing on separate MySQL connections. Each side is
     * ['op' => whitelisted operation name, 'actor_id' => int, 'subject_id' => int]
     * (operations are resolved in .phpunit-concurrency-actor.php, never eval'd).
     */
    public function runServiceRace(array $sideA, array $sideB): array
    {
        $this->ensureRuntimeDir();
        $readyA = $this->runtimeDir.'/sr-a.ready';
        $readyB = $this->runtimeDir.'/sr-b.ready';
        $release = $this->runtimeDir.'/sr.release';
        $resultA = $this->runtimeDir.'/sr-a.result.json';
        $resultB = $this->runtimeDir.'/sr-b.result.json';
        $paths = [$readyA, $readyB, $release, $resultA, $resultB];
        $this->cleanupFiles($paths);

        $this->actorStartup = [];
        $aProcess = null;

        try {
            $aProcess = $this->startServiceRaceActorUntilReady('a', $sideA, $readyA, $readyB, $release, $resultA, $resultB, $readyA);
            $bProcess = $this->startServiceRaceActorUntilReady('b', $sideB, $readyA, $readyB, $release, $resultA, $resultB, $readyB);
            $this->writeFile($release, 'go');

            $aProcess->wait();
            $bProcess->wait();
            $aResult = $this->readJson($resultA);
            $bResult = $this->readJson($resultB);

            if (($aResult['connection_id'] ?? null) === ($bResult['connection_id'] ?? null)) {
                throw new \RuntimeException('Service race actors did not use different MySQL connections.');
            }

            if (($aResult['started_at'] ?? 0) >= ($bResult['completed_at'] ?? 0)
                || ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
                throw new \RuntimeException('Service race actors did not overlap in time.');
            }

            return [
                'a' => $aResult,
                'b' => $bResult,
                'different_connections' => true,
                'true_overlap' => true,
                'startup' => $this->actorStartup,
            ];
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            if ($aProcess !== null) {
                $this->cleanupProcess($aProcess);
            }
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    /**
     * Launch one service-race actor and wait for its readiness signal, with a single bounded respawn.
     *
     * The ready file is written BEFORE the actor touches any business state or opens a transaction, so an
     * actor that is still alive but has not signalled has done nothing that matters: it is stopped and
     * launched once more, and the first attempt's diagnostics (including the [actor-boot] breadcrumbs) are
     * kept. A crashed actor (process exited) is never retried, and a second stall fails the test with both
     * diagnostics. The race itself is unchanged: the release barrier is only written after BOTH actors are
     * ready, so the operations still start together on separate connections.
     */
    /** Seam over Symfony's stop(): one place to stop an actor (overridden only by the harness self-test). */
    protected function stopActorProcess(Process $process): void
    {
        $this->cleanupProcess($process);
    }

    /**
     * Stop an actor and PROVE it is gone. Symfony's stop() swallows kill failures, so the state is re-read
     * for up to 5s; a process that is still running afterwards aborts the run instead of being replaced.
     */
    protected function terminateActorVerified(Process $process, string $label): void
    {
        $this->stopActorProcess($process);

        $deadline = microtime(true) + 5;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(50000);
        }

        if ($process->isRunning()) {
            throw new \RuntimeException(sprintf('ACTOR TERMINATION NOT VERIFIED: %s (pid %s) is still running; refusing to launch a replacement.', $label, var_export($process->getPid(), true)));
        }
    }

    protected function startServiceRaceActorUntilReady(string $side, array $config, string $readyA, string $readyB, string $release, string $resultA, string $resultB, string $readyPath): Process
    {
        $label = 'sr-'.$side;
        $firstStall = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $spawnedAt = microtime(true);
            $process = $this->spawnServiceRaceActor($side, $config, $readyA, $readyB, $release, $resultA, $resultB);

            try {
                $this->waitForActorSignal($readyPath, $this->readyTimeoutSeconds, $process, $label);
                $this->actorStartup[$label] = [
                    'ready_seconds' => round(microtime(true) - $spawnedAt, 3),
                    'attempts' => $attempt,
                    'first_attempt_stall' => $firstStall,
                ];

                return $process;
            } catch (\RuntimeException $stall) {
                if ($process->isRunning() && file_exists($readyPath)) {
                    // Signalled in the instant after the deadline: it is ready, do not fail or replace it.
                    $this->actorStartup[$label] = [
                        'ready_seconds' => round(microtime(true) - $spawnedAt, 3),
                        'attempts' => $attempt,
                        'first_attempt_stall' => $firstStall,
                    ];

                    return $process;
                }
                $stalledAlive = $process->isRunning();
                if ($attempt === 2 || ! $stalledAlive) {
                    $this->stopActorProcess($process);
                    throw new \RuntimeException($firstStall === null
                        ? $stall->getMessage()
                        : $stall->getMessage()."\n  FIRST ATTEMPT ALSO STALLED:\n  ".$firstStall);
                }
                $firstStall = $stall->getMessage();
                // Fail closed: the replacement may only start once the old actor is PROVEN dead, so two actors
                // of one role can never overlap and a late write from the old one cannot hit the new attempt.
                $this->terminateActorVerified($process, $label);
                // Only now (nothing can write any more) scrub this side's signals so attempt 2 starts clean.
                $this->cleanupFiles([$readyPath, $side === 'a' ? $resultA : $resultB]);
            }
        }

        throw new \LogicException('unreachable');
    }

    public function runDispatchRace(array $sideA, array $sideB): array
    {
        // A1-04: a Gudang fulfillment proposal racing a dispatch assignment on the same order.
        // Both sides start simultaneously on separate connections; the canonical Order-first lock
        // serializes them so neither can observe a stale eligibility state.
        $this->ensureRuntimeDir();
        $readyA = $this->runtimeDir.'/dr-a.ready';
        $readyB = $this->runtimeDir.'/dr-b.ready';
        $release = $this->runtimeDir.'/dr.release';
        $resultA = $this->runtimeDir.'/dr-a.result.json';
        $resultB = $this->runtimeDir.'/dr-b.result.json';
        $paths = [$readyA, $readyB, $release, $resultA, $resultB];
        $this->cleanupFiles($paths);

        $aArgs = $this->dispatchRaceArgs('dr-a', $readyA, $release, $resultA, $sideA);
        $aProcess = $this->startProcess($aArgs);

        try {
            $this->waitForActorSignal($readyA, 15, $aProcess, 'dr-a');
            $bArgs = $this->dispatchRaceArgs('dr-b', $readyB, $release, $resultB, $sideB);
            $bProcess = $this->startProcess($bArgs);
            $this->waitForActorSignal($readyB, 15, $bProcess, 'dr-b', $resultB);
            $this->writeFile($release, 'go');

            $aProcess->wait();
            $bProcess->wait();
            $aResult = $this->readJson($resultA);
            $bResult = $this->readJson($resultB);

            if (($aResult['connection_id'] ?? null) === ($bResult['connection_id'] ?? null)) {
                throw new \RuntimeException('Dispatch race actors did not use different MySQL connections.');
            }

            if (($aResult['started_at'] ?? 0) >= ($bResult['completed_at'] ?? 0)
                || ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
                throw new \RuntimeException('Dispatch race actors did not overlap in time.');
            }

            return [
                'a' => $aResult,
                'b' => $bResult,
                'different_connections' => true,
                'true_overlap' => true,
            ];
        } finally {
            if (isset($bProcess)) {
                $this->cleanupProcess($bProcess);
            }
            $this->cleanupProcess($aProcess);
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    protected function dispatchRaceArgs(string $role, string $ready, string $release, string $result, array $side): array
    {
        // The actor dispatches on `--role` prefix `dispatch-race-`; the harness
        // passes a unique suffix per side (a/b).
        $actorRole = 'dispatch-race-'.$role;
        $args = [
            '--role='.$actorRole, '--runtime-dir='.$this->runtimeDir,
            '--dispatch-race-ready='.$ready, '--dispatch-race-release='.$release,
            '--dispatch-race-result='.$result,
            '--dispatch-race-operation='.(string) $side['op'],
            '--dispatch-race-actor='.(int) $side['actor_id'],
            '--dispatch-race-order='.(int) ($side['order_id'] ?? 0),
            '--dispatch-race-request='.(int) ($side['request_id'] ?? 0),
            '--dispatch-race-item='.(int) ($side['item_id'] ?? 0),
            '--dispatch-race-courier='.(int) ($side['courier_id'] ?? 0),
            '--dispatch-race-quantity='.(int) ($side['quantity'] ?? 1),
            '--dispatch-race-expected='.(int) ($side['expected'] ?? 0),
            '--dispatch-race-date='.(string) ($side['date'] ?? ''),
        ];

        return $args;
    }

    public function runOrderLockedStateRace(array $configuration, string $operation): array
    {
        $this->ensureRuntimeDir();
        $locked = $this->runtimeDir.'/order-race.locked';
        $observed = $this->runtimeDir.'/order-race.observed';
        $release = $this->runtimeDir.'/order-race.release';
        $writerResult = $this->runtimeDir.'/order-race-writer.result.json';
        $serviceResult = $this->runtimeDir.'/order-race-service.result.json';
        $paths = [$locked, $observed, $release, $writerResult, $serviceResult];
        $this->cleanupFiles($paths);

        $writer = $this->startProcess([
            '--role=order-race-writer', '--runtime-dir='.$this->runtimeDir,
            '--order-race-locked='.$locked, '--order-race-observed='.$observed,
            '--order-race-release='.$release, '--order-race-writer-result='.$writerResult,
            '--order-race-service-result='.$serviceResult, '--order-race-order='.(int) $configuration['order_id'],
            '--order-race-actor='.(int) $configuration['actor_id'], '--order-race-operation='.$operation,
        ]);

        try {
            $this->waitForSignal($locked, 15);
            $service = $this->startProcess([
                '--role=order-race-service', '--runtime-dir='.$this->runtimeDir,
                '--order-race-locked='.$locked, '--order-race-observed='.$observed,
                '--order-race-release='.$release, '--order-race-writer-result='.$writerResult,
                '--order-race-service-result='.$serviceResult, '--order-race-order='.(int) $configuration['order_id'],
                '--order-race-actor='.(int) $configuration['actor_id'], '--order-race-operation='.$operation,
            ]);
            $this->waitForSignal($observed, 15);
            $this->writeFile($release, 'go');
            $writer->wait();
            $service->wait();

            $a = $this->readJson($writerResult);
            $b = $this->readJson($serviceResult);

            return [
                'writer' => $a, 'service' => $b,
                'different_connections' => ($a['connection_id'] ?? null) !== ($b['connection_id'] ?? null),
                'stale_observation' => ($b['observed_status'] ?? null) !== ($a['committed_status'] ?? null),
            ];
        } finally {
            if (isset($service)) {
                $this->cleanupProcess($service);
            }
            $this->cleanupProcess($writer);
            $this->cleanupFiles($paths);
            $this->cleanupBarrierDir();
        }
    }

    public function staleBarrierPaths(): array
    {
        $paths = [];
        $dir = $this->root.'/storage/framework/testing/concurrency';
        if (! is_dir($dir)) {
            return $paths;
        }

        $entries = scandir($dir);
        foreach ($entries ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            $paths[] = $path;
        }

        return $paths;
    }

    public function orphanProcesses(): array
    {
        $process = Process::fromShellCommandline("ps -eo pid,ppid,comm,args --no-headers | grep '.phpunit-concurrency-actor.php' | grep -v grep || true");
        $process->run();
        $output = trim($process->getOutput());

        if ($output === '') {
            return [];
        }

        return preg_split('/\R+/', $output) ?: [];
    }

    protected function ensureRuntimeDir(): void
    {
        // THR-04: every actor batch starts here — refuse it unless this runner still owns the test database.
        TestRunLock::assertCurrentOwned('start a concurrency actor batch');
        $dir = $this->root.'/storage/framework/testing/concurrency';
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create concurrency runtime directory.');
        }
        if (! is_dir($this->runtimeDir) && ! mkdir($this->runtimeDir, 0775, true) && ! is_dir($this->runtimeDir)) {
            throw new \RuntimeException('Unable to create per-run concurrency directory.');
        }
    }

    protected function spawnActor(string $name): Process
    {
        TestRunLock::assertCurrentOwned('launch concurrency actor '.$name);
        $script = $this->root.'/.phpunit-concurrency-actor.php';
        $process = new Process([
            PHP_BINARY,
            $script,
            '--role='.$name,
            '--runtime-dir='.$this->runtimeDir,
            '--actor-a-ready='.$this->actorAReady,
            '--actor-b-attempt='.$this->actorBAttempt,
            '--release-file='.$this->releaseFile,
            '--actor-a-result='.$this->actorAResult,
            '--actor-b-result='.$this->actorBResult,
        ], $this->root, $this->actorEnvironment(), null, 30);
        $process->start();

        return $process;
    }

    protected function spawnCheckoutActor(string $role, array $configuration, string $readyA, string $readyB, string $release, string $resultA, string $resultB): Process
    {
        return $this->startProcess([
            '--role=checkout-'.$role,
            '--runtime-dir='.$this->runtimeDir,
            '--checkout-ready-a='.$readyA,
            '--checkout-ready-b='.$readyB,
            '--checkout-release='.$release,
            '--checkout-result-a='.$resultA,
            '--checkout-result-b='.$resultB,
            '--checkout-buyer-a='.(int) $configuration['buyer_a_id'],
            '--checkout-buyer-b='.(int) $configuration['buyer_b_id'],
            '--checkout-product='.(int) $configuration['product_id'],
            '--checkout-agent='.(int) $configuration['agent_id'],
            '--checkout-quantity='.(int) ($configuration['quantity'] ?? 1),
            '--checkout-destination='.base64_encode(json_encode($configuration['destination'], JSON_THROW_ON_ERROR)),
        ]);
    }

    protected function spawnFulfillmentActor(string $role, array $configuration, string $readyA, string $readyB, string $release, string $resultA, string $resultB): Process
    {
        return $this->startProcess([
            '--role=fulfillment-'.$role,
            '--runtime-dir='.$this->runtimeDir,
            '--fulfillment-ready-a='.$readyA,
            '--fulfillment-ready-b='.$readyB,
            '--fulfillment-release='.$release,
            '--fulfillment-result-a='.$resultA,
            '--fulfillment-result-b='.$resultB,
            '--fulfillment-actor-a='.(int) $configuration['actor_a_id'],
            '--fulfillment-actor-b='.(int) $configuration['actor_b_id'],
            '--fulfillment-request='.(int) $configuration['request_id'],
            '--fulfillment-item='.(int) $configuration['item_id'],
            '--fulfillment-quantity='.(int) ($configuration['quantity'] ?? 6),
            ...(isset($configuration['proposal_id']) ? ['--fulfillment-proposal='.(int) $configuration['proposal_id']] : []),
        ]);
    }

    protected function spawnFulfillmentCancellationActor(string $role, array $configuration, string $readyA, string $readyB, string $release, string $resultA, string $resultB): Process
    {
        return $this->startProcess([
            '--role=fc-'.$role,
            '--runtime-dir='.$this->runtimeDir,
            '--fc-ready-a='.$readyA,
            '--fc-ready-b='.$readyB,
            '--fc-release='.$release,
            '--fc-result-a='.$resultA,
            '--fc-result-b='.$resultB,
            '--fc-fulfillment-actor='.(int) $configuration['fulfillment_actor_id'],
            '--fc-cancellation-actor='.(int) $configuration['cancellation_actor_id'],
            '--fc-order='.(int) $configuration['order_id'],
            '--fc-request='.(int) $configuration['request_id'],
            '--fc-item='.(int) $configuration['item_id'],
            '--fc-quantity='.(int) ($configuration['quantity'] ?? 6),
            ...(isset($configuration['proposal_id']) ? ['--fc-proposal='.(int) $configuration['proposal_id']] : []),
        ]);
    }

    protected function spawnTransferOpnameActor(string $role, array $configuration, string $readyA, string $readyB, string $release, string $resultA, string $resultB): Process
    {
        return $this->startProcess([
            '--role=to-'.$role,
            '--runtime-dir='.$this->runtimeDir,
            '--to-ready-a='.$readyA,
            '--to-ready-b='.$readyB,
            '--to-release='.$release,
            '--to-result-a='.$resultA,
            '--to-result-b='.$resultB,
            '--to-transfer-actor='.(int) $configuration['transfer_actor_id'],
            '--to-opname-actor='.(int) $configuration['opname_actor_id'],
            '--to-transfer='.(int) $configuration['transfer_id'],
            '--to-opname='.(int) $configuration['opname_id'],
        ]);
    }

    protected function spawnServiceRaceActor(string $side, array $config, string $readyA, string $readyB, string $release, string $resultA, string $resultB): Process
    {
        $arguments = [
            '--role=sr-'.$side,
            '--runtime-dir='.$this->runtimeDir,
            '--sr-ready-a='.$readyA,
            '--sr-ready-b='.$readyB,
            '--sr-release='.$release,
            '--sr-result-a='.$resultA,
            '--sr-result-b='.$resultB,
            '--sr-op='.$config['op'],
            '--sr-actor='.(int) $config['actor_id'],
            '--sr-subject='.(int) ($config['subject_id'] ?? 0),
        ];
        // Generic bag for ops that need more than one subject id (e.g. an Agent
        // stock-capacity reservation needs agent/product/variation/quantity, never
        // eval'd — only ever json_decode'd in .phpunit-concurrency-actor.php).
        if (isset($config['extra'])) {
            $arguments[] = '--sr-extra='.base64_encode(json_encode($config['extra']));
        }

        return $this->startProcess($arguments);
    }

    protected function startProcess(array $arguments): Process
    {
        TestRunLock::assertCurrentOwned('launch a concurrency actor');
        $process = new Process(array_merge([PHP_BINARY, $this->root.'/.phpunit-concurrency-actor.php'], $arguments), $this->root, $this->actorEnvironment(), null, 30);
        $process->start();

        return $process;
    }

    protected function actorEnvironment(): array
    {
        // Fail closed: the PHPUnit parent itself must already be running
        // APP_ENV=testing against exactly primeclassy_testing. Never fall back.
        TestDatabaseGuard::assertApplicationSafe(app());

        $connection = (string) config('database.default');
        $settings = config("database.connections.{$connection}");

        $environment = [
            'APP_ENV' => TestDatabaseGuard::ENVIRONMENT,
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) ($settings['host'] ?? ''),
            'DB_PORT' => (string) ($settings['port'] ?? ''),
            'DB_DATABASE' => TestDatabaseGuard::DATABASE,
            'DB_USERNAME' => (string) ($settings['username'] ?? ''),
            'DB_PASSWORD' => (string) ($settings['password'] ?? ''),
        ];

        if (! empty($settings['unix_socket'])) {
            $environment['DB_SOCKET'] = (string) $settings['unix_socket'];
        }

        return $environment;
    }

    /**
     * Readiness wait with evidence. Same budget as waitForSignal, but it also stops early when the actor
     * process has already exited without signalling, and on failure attaches bounded diagnostics
     * (actor, signal path, elapsed, process state, exit code, stdout/stderr, runtime signal files).
     */
    protected function waitForActorSignal(string $signalPath, int $seconds, Process $process, string $actor, ?string $resultPath = null): void
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + $seconds;
        while (microtime(true) < $deadline) {
            if (file_exists($signalPath)) {
                return;
            }
            if (! $process->isRunning()) {
                // Exited: give the filesystem one last look, then stop waiting for a signal that cannot come.
                if (file_exists($signalPath)) {
                    return;
                }
                break;
            }
            usleep(100000);
        }

        throw new \RuntimeException($this->actorDiagnostics($signalPath, $startedAt, $process, $actor, $resultPath));
    }

    protected function actorDiagnostics(string $signalPath, float $startedAt, Process $process, string $actor, ?string $resultPath): string
    {
        $running = $process->isRunning();
        $signals = [];
        if (is_dir($this->runtimeDir)) {
            $signals = array_slice(array_map('basename', glob($this->runtimeDir.'/*') ?: []), 0, 30);
        }

        $secret = (string) (config('database.connections.'.config('database.default').'.password') ?? '');
        $bounded = static function (string $text) use ($secret): string {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }

            return substr($text, 0, 1500);
        };

        return sprintf(
            "Timed out waiting for concurrency signal: %s\n  actor=%s elapsed=%.1fs process=%s exit_code=%s\n  stdout=%s\n  stderr=%s\n  result=%s\n  runtime_files=[%s]",
            $signalPath,
            $actor,
            microtime(true) - $startedAt,
            $running ? 'running' : 'exited',
            $running ? 'n/a' : var_export($process->getExitCode(), true),
            $bounded($process->getOutput()),
            $bounded($process->getErrorOutput()),
            $resultPath !== null && is_file($resultPath) ? $bounded((string) file_get_contents($resultPath)) : 'n/a',
            implode(', ', $signals)
        );
    }

    protected function waitForSignal(string $signalPath, int $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (file_exists($signalPath)) {
                return;
            }
            usleep(100000);
        }

        throw new \RuntimeException('Timed out waiting for concurrency signal: '.$signalPath);
    }

    protected function writeRelease(): void
    {
        file_put_contents($this->releaseFile, 'release');
    }

    protected function writeFile(string $path, string $contents): void
    {
        file_put_contents($path, $contents);
    }

    protected function readJson(string $path): array
    {
        // Actors have finished (or failed); prove ownership before their results drive further work.
        TestRunLock::assertCurrentOwned('use concurrency actor results');
        if (! file_exists($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function assertAllSucceeded(Process $aProcess, Process $bProcess, array $aResult, array $bResult): void
    {
        if (! $aProcess->isTerminated() && $aProcess->getStatus() !== 0) {
            throw new \RuntimeException('Actor A failed: '.$aProcess->getErrorOutput());
        }
        if (! $bProcess->isTerminated() && $bProcess->getStatus() !== 0) {
            throw new \RuntimeException('Actor B failed: '.$bProcess->getErrorOutput());
        }
        if (($aResult['success'] ?? false) !== true) {
            throw new \RuntimeException('Actor A did not finish successfully.');
        }
        if (($bResult['success'] ?? false) !== true) {
            throw new \RuntimeException('Actor B did not finish successfully.');
        }
    }

    protected function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    protected function cleanupProcess(Process $process): void
    {
        if (! $process->isRunning()) {
            return;
        }

        $process->stop(3, defined('SIGTERM') ? SIGTERM : null);
    }

    protected function cleanupBarrierDir(): void
    {
        if (is_dir($this->runtimeDir)) {
            $this->cleanupFiles([
                $this->actorAReady,
                $this->actorBAttempt,
                $this->releaseFile,
                $this->actorAResult,
                $this->actorBResult,
            ]);
            // Actors may drop extra marker files beside the declared barrier files (e.g. the capacity-race
            // breadcrumbs `<ready>.capacity-attempt` / `<ready>.first-capacity-lock`). Only THIS harness
            // instance's own per-run directory is swept, so a leftover can never survive to fail later tests
            // and another run's directory is never touched.
            foreach (glob($this->runtimeDir.'/*') ?: [] as $leftover) {
                if (is_file($leftover) || is_link($leftover)) {
                    @unlink($leftover);
                }
            }
            @rmdir($this->runtimeDir);
        }
    }
}
