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
                && ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
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
                && ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
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
                && ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
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
                && ($bResult['started_at'] ?? 0) >= ($aResult['completed_at'] ?? 0)) {
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

    protected function startProcess(array $arguments): Process
    {
        $process = new Process(array_merge([PHP_BINARY, $this->root.'/.phpunit-concurrency-actor.php'], $arguments), $this->root, $this->actorEnvironment(), null, 30);
        $process->start();

        return $process;
    }

    protected function actorEnvironment(): array
    {
        $database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null);

        if (! is_string($database) || ! preg_match('/_test(?:ing)?(?:_|$)/', $database)) {
            throw new \RuntimeException('REFUSED: refusing to spawn a concurrency actor against a non-test database.');
        }

        $environment = ['APP_ENV' => 'testing'];

        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET'] as $key) {
            $value = getenv($key);
            if ($value === false) {
                $value = $_ENV[$key] ?? $_SERVER[$key] ?? false;
            }
            if ($value !== false && $value !== null && $value !== '') {
                $environment[$key] = (string) $value;
            }
        }

        return $environment;
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

        $process->stop(3, SIGTERM);
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
            @rmdir($this->runtimeDir);
        }
    }
}
