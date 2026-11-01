<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\Support\ConcurrencyHarness;
use Tests\TestCase;

/**
 * Harness self-test for the actor readiness wait: a launch that stays alive without ever signalling is
 * stopped and relaunched exactly once, a crashed launch is never retried, and two stalls fail with both
 * diagnostics. No database and no real actor — only the orchestration logic is exercised.
 */
class ConcurrencyHarnessStartupTest extends TestCase
{
    public function test_a_stalled_first_launch_is_relaunched_once_and_the_startup_is_recorded(): void
    {
        $harness = new StartupProbeHarness(['stall', 'ready']);

        $process = $harness->start();

        $this->assertSame(2, $harness->launches);
        $this->assertSame(2, $harness->startup()['sr-a']['attempts']);
        $this->assertStringContainsString('Timed out waiting for concurrency signal', (string) $harness->startup()['sr-a']['first_attempt_stall']);
        $this->assertStringContainsString('[actor-boot] phase=stalled-here', (string) $harness->startup()['sr-a']['first_attempt_stall']);
        $this->assertFileExists($harness->readyPath());
        $this->assertSame(1, $harness->stalledProcessesStillRunning(), 'only the relaunched actor may be alive');
        $process->stop(1);
    }

    public function test_a_prompt_first_launch_is_not_relaunched(): void
    {
        $harness = new StartupProbeHarness(['ready']);

        $process = $harness->start();

        $this->assertSame(1, $harness->launches);
        $this->assertSame(1, $harness->startup()['sr-a']['attempts']);
        $this->assertNull($harness->startup()['sr-a']['first_attempt_stall']);
        $process->stop(1);
    }

    public function test_a_crashed_launch_is_never_relaunched(): void
    {
        $harness = new StartupProbeHarness(['crash', 'ready']);

        try {
            $harness->start();
            $this->fail('A crashed actor must fail the wait.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('process=exited', $e->getMessage());
        }

        $this->assertSame(1, $harness->launches);
    }

    public function test_two_stalled_launches_fail_with_both_diagnostics_and_leave_nothing_running(): void
    {
        $harness = new StartupProbeHarness(['stall', 'stall']);

        try {
            $harness->start();
            $this->fail('Two stalls must fail the wait.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('FIRST ATTEMPT ALSO STALLED', $e->getMessage());
        }

        $this->assertSame(2, $harness->launches);
        $this->assertSame(0, $harness->stalledProcessesStillRunning());
    }

    public function test_the_replacement_starts_with_clean_signals_and_never_beside_a_live_actor(): void
    {
        $harness = new StartupProbeHarness(['stall-dirty', 'ready']);

        $process = $harness->start();

        $this->assertSame(2, $harness->launches);
        $this->assertSame([false, false], $harness->readyAtLaunch, 'no stale ready signal may exist when an attempt starts');
        $this->assertSame([false, false], $harness->resultAtLaunch, 'no stale result may exist when an attempt starts');
        $this->assertSame([0, 0], $harness->liveActorsAtLaunch, 'the old actor must be dead before the replacement launches');
        $process->stop(1);
    }

    public function test_an_actor_that_cannot_be_proven_dead_aborts_the_run_without_a_replacement(): void
    {
        $harness = new StartupProbeHarness(['stall', 'ready']);
        $harness->refuseToDie = true;

        try {
            $harness->start();
            $this->fail('An unverified termination must abort the wait.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ACTOR TERMINATION NOT VERIFIED', $e->getMessage());
        }

        $this->assertSame(1, $harness->launches, 'no replacement may be launched');
    }
}

class StartupProbeHarness extends ConcurrencyHarness
{
    public int $launches = 0;

    /** When true the harness "forgets" to stop actors, simulating a kill that did not take effect. */
    public bool $refuseToDie = false;

    /** @var list<bool> */
    public array $readyAtLaunch = [];

    /** @var list<bool> */
    public array $resultAtLaunch = [];

    /** @var list<int> */
    public array $liveActorsAtLaunch = [];

    /** @var list<Process> */
    protected array $spawned = [];

    /** @param list<string> $script behaviour of each successive launch: stall | ready | crash */
    public function __construct(protected array $script)
    {
        parent::__construct();
        $this->readyTimeoutSeconds = 1;
        if (! is_dir($this->runtimeDir)) {
            mkdir($this->runtimeDir, 0775, true);
        }
    }

    public function __destruct()
    {
        foreach ($this->spawned as $process) {
            $this->cleanupProcess($process);
        }
        @unlink($this->readyPath());
        @unlink($this->resultPath());
        @rmdir($this->runtimeDir);
    }

    public function readyPath(): string
    {
        return $this->runtimeDir.'/sr-a.ready';
    }

    public function resultPath(): string
    {
        return $this->runtimeDir.'/sr-a.result.json';
    }

    protected function stopActorProcess(Process $process): void
    {
        if (! $this->refuseToDie) {
            parent::stopActorProcess($process);
        }
    }

    public function start(): Process
    {
        return $this->startServiceRaceActorUntilReady('a', ['op' => 'noop', 'actor_id' => 0], $this->readyPath(), '', '', $this->resultPath(), '', $this->readyPath());
    }

    public function startup(): array
    {
        return $this->actorStartup;
    }

    public function stalledProcessesStillRunning(): int
    {
        return count(array_filter($this->spawned, fn (Process $p) => $p->isRunning()));
    }

    protected function spawnServiceRaceActor(string $side, array $config, string $readyA, string $readyB, string $release, string $resultA, string $resultB): Process
    {
        $behaviour = $this->script[$this->launches++] ?? 'stall';
        $ready = $this->readyPath();
        $this->readyAtLaunch[] = file_exists($ready);
        $this->resultAtLaunch[] = file_exists($this->resultPath());
        $this->liveActorsAtLaunch[] = $this->stalledProcessesStillRunning();
        $code = match ($behaviour) {
            'ready' => 'file_put_contents('.var_export($ready, true).", 'ready'); sleep(30);",
            'crash' => 'fwrite(STDERR, "boom"); exit(9);',
            'stall-dirty' => 'file_put_contents('.var_export($this->resultPath(), true).", 'stale'); fwrite(STDERR, \"[actor-boot] phase=stalled-here\n\"); sleep(30);",
            default => 'fwrite(STDERR, "[actor-boot] phase=stalled-here\n"); sleep(30);',
        };
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->start();

        return $this->spawned[] = $process;
    }
}
