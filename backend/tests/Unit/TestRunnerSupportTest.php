<?php

namespace Tests\Unit;

use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/scripts/test-runner-support.php';

/**
 * THR-05 (JUnit path) and THR-06 (git dirty metadata) for scripts/run-tests-serialized.php.
 *
 * Extends Tests\TestCase on purpose: the end-to-end case runs the real runner nested inside
 * this process, which holds the MySQL run lock — so the nested PHPUnit is refused by the
 * lock and yields a deterministic, database-free failing fixture.
 */
class TestRunnerSupportTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pc runner '.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        foreach (['FAKE_GIT_STDOUT', 'FAKE_GIT_STDERR', 'FAKE_GIT_EXIT'] as $var) {
            putenv($var);
        }
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0666);
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
            }
        }
        @rmdir($path);
    }

    /* ----------------------------------------------------------------- THR-05 resolve */

    public function test_default_junit_path_is_appended_once(): void
    {
        $r = primeclassy_resolve_junit(['tests/Unit'], '/runs/x.junit.xml');

        $this->assertSame(['tests/Unit', '--log-junit', '/runs/x.junit.xml'], $r['arguments']);
        $this->assertSame('/runs/x.junit.xml', $r['path']);
        $this->assertFalse($r['custom']);
    }

    public function test_custom_separated_junit_argument_is_the_effective_path(): void
    {
        $r = primeclassy_resolve_junit(['--log-junit', 'C:\\out dir\\my junit.xml', 'tests/Unit'], '/runs/x.junit.xml');

        $this->assertSame(['--log-junit', 'C:\\out dir\\my junit.xml', 'tests/Unit'], $r['arguments']);
        $this->assertSame('C:\\out dir\\my junit.xml', $r['path']);
        $this->assertTrue($r['custom']);
    }

    public function test_custom_equals_junit_argument_is_the_effective_path(): void
    {
        $r = primeclassy_resolve_junit(['tests/Unit', '--log-junit=C:\\out dir\\my junit.xml'], '/runs/x.junit.xml');

        $this->assertSame(['tests/Unit', '--log-junit=C:\\out dir\\my junit.xml'], $r['arguments']);
        $this->assertSame('C:\\out dir\\my junit.xml', $r['path']);
        $this->assertTrue($r['custom']);
    }

    public function test_junit_flag_without_a_path_has_no_effective_path(): void
    {
        $this->assertNull(primeclassy_resolve_junit(['--log-junit'], '/d.xml')['path']);
        $this->assertNull(primeclassy_resolve_junit(['--log-junit', '--filter=x'], '/d.xml')['path']);
        $this->assertNull(primeclassy_resolve_junit(['--log-junit='], '/d.xml')['path']);
    }

    /* ----------------------------------------------------------------- THR-05 classify */

    public function test_missing_unreadable_or_invalid_junit_is_unavailable_not_zero(): void
    {
        foreach ([null, $this->tmp.'/does not exist.xml'] as $path) {
            $r = primeclassy_classify_junit($path);
            $this->assertFalse($r['available']);
            $this->assertNull($r['test']);
            $this->assertNull($r['environment']);
        }

        file_put_contents($bad = $this->tmp.'/broken junit.xml', '<testsuites><testsuite');
        $r = primeclassy_classify_junit($bad);
        $this->assertFalse($r['available']);
        $this->assertSame('JUnit file is not valid XML', $r['reason']);
    }

    public function test_failures_are_classified_from_the_given_file(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<testsuites><testsuite name="s">
  <testcase name="ok" class="Tests\Feature\AnyTest"/>
  <testcase name="env" class="Tests\Feature\AnyTest"><error>RuntimeException: TEST DATABASE ALREADY IN USE: another runner</error></testcase>
  <testcase name="lost" class="Tests\Feature\AnyTest"><error>RuntimeException: TEST RUN LOCK LOST: refusing to reset</error></testcase>
  <testcase name="ddl" class="Tests\Feature\AnyTest"><error>SQLSTATE[42S01]: Base table or view already exists: 1050</error></testcase>
  <testcase name="mig" class="Tests\Feature\AnyTest"><failure>migration 2026_01_01 did not run</failure></testcase>
  <testcase name="race" class="Tests\Feature\CheckoutConcurrencyTest"><failure>Failed asserting that 2 is 1</failure></testcase>
  <testcase name="plain" class="Tests\Feature\AnyTest"><failure>Failed asserting that false is true.</failure></testcase>
</testsuite></testsuites>
XML;
        file_put_contents($path = $this->tmp.'/custom junit.xml', $xml);

        $r = primeclassy_classify_junit($path);

        $this->assertTrue($r['available']);
        $this->assertSame([3, 1, 1, 1], [$r['environment'], $r['migration'], $r['concurrency'], $r['test']]);
        $this->assertCount(6, $r['items']);
    }

    /* ----------------------------------------------------------------- THR-05 end to end */

    /** @return array{process: Process, summary: array<string, mixed>} */
    private function runNestedRunner(string $junitArgumentStyle, string $junitPath): array
    {
        $root = dirname(__DIR__, 2);
        $junitArgs = $junitArgumentStyle === 'equals' ? ['--log-junit='.$junitPath] : ['--log-junit', $junitPath];
        $process = new Process(
            array_merge([PHP_BINARY, $root.'/scripts/run-tests-serialized.php', 'tests/Unit/IsolatedSchemaFingerprintTest.php', '--filter=test_fingerprint_is_deterministic_when_nothing_changed'], $junitArgs),
            $root,
            ['PRIMECLASSY_SERIALIZED_LOCK_PATH' => $this->tmp.DIRECTORY_SEPARATOR.'serialized.lock', 'PRIMECLASSY_TEST_RUN_ID' => false],
            null,
            120,
        );
        $process->run();

        $this->assertMatchesRegularExpression('/RUN ([0-9a-f-]{36}) exit=(\d+)/', $process->getOutput(), $process->getErrorOutput());
        preg_match('/RUN ([0-9a-f-]{36}) exit=(\d+)/', $process->getOutput(), $m);
        $summaryPath = $root.'/storage/framework/testing/runs/'.$m[1].'.summary.json';
        $this->assertFileExists($summaryPath);
        $summary = json_decode((string) file_get_contents($summaryPath), true);

        // Do not leave artifacts of this nested fixture run behind.
        foreach (glob($root.'/storage/framework/testing/runs/'.$m[1].'.*') ?: [] as $file) {
            @unlink($file);
        }

        return ['process' => $process, 'summary' => $summary];
    }

    public function test_runner_uses_one_custom_junit_path_and_reports_the_real_exit_code(): void
    {
        foreach (['equals', 'separated'] as $style) {
            $junitPath = $this->tmp.DIRECTORY_SEPARATOR.$style.' junit result.xml';
            ['process' => $process, 'summary' => $s] = $this->runNestedRunner($style, $junitPath);

            // The nested PHPUnit is refused by this process's run lock => a real, deterministic failure.
            $this->assertNotSame(0, $process->getExitCode(), $style);
            $this->assertSame($process->getExitCode(), $s['exit_code'], $style.': summary exit code = process exit code');
            $this->assertSame($junitPath, $s['junit']['path'], $style);
            $this->assertTrue($s['junit']['custom'], $style);
            $this->assertFileExists($junitPath, $style.': PHPUnit wrote the custom path');
            $this->assertTrue($s['failures']['available'], $style.': classifier read the custom path');
            $this->assertSame(1, $s['failures']['environment'], $style.': lock refusal classified as environment');
            $this->assertStringContainsString('ALREADY IN USE', $s['failures']['items'][0]['message']);
            $this->assertTrue($s['git']['available'] === false || is_int($s['git']['paths']), $style);
        }
    }

    /* ----------------------------------------------------------------- THR-06 git */

    private function git(string $repo, string ...$args): void
    {
        $p = new Process(array_merge(['git', '-C', $repo, '-c', 'user.email=t@example.invalid', '-c', 'user.name=t', '-c', 'core.autocrlf=false'], $args));
        $p->mustRun();
    }

    private function repo(): string
    {
        $repo = $this->tmp.DIRECTORY_SEPARATOR.'repo';
        mkdir($repo);
        $this->git($repo, 'init', '-q');
        file_put_contents($repo.'/tracked.txt', "a\n");
        $this->git($repo, 'add', 'tracked.txt');
        $this->git($repo, 'commit', '-q', '-m', 'init');

        return $repo;
    }

    public function test_git_clean_modified_untracked_and_spaced_names(): void
    {
        $repo = $this->repo();

        $this->assertSame(['available' => true, 'dirty' => false, 'paths' => 0, 'error' => null], primeclassy_git_dirty($repo));

        file_put_contents($repo.'/tracked.txt', "b\n");
        $this->assertSame(1, primeclassy_git_dirty($repo)['paths'], 'modified');

        file_put_contents($repo.'/new file with spaces.txt', 'x');
        file_put_contents($repo.'/other.txt', 'y');
        $r = primeclassy_git_dirty($repo);
        $this->assertTrue($r['dirty']);
        $this->assertSame(3, $r['paths'], 'modified + two untracked, one with spaces');
    }

    public function test_git_failure_is_unavailable_not_clean(): void
    {
        $notRepo = $this->tmp.DIRECTORY_SEPARATOR.'not a repo';
        mkdir($notRepo);

        $r = primeclassy_git_dirty($notRepo);
        $this->assertFalse($r['available']);
        $this->assertNull($r['dirty']);
        $this->assertNull($r['paths']);

        $this->assertFalse(primeclassy_git_dirty($this->tmp, ['git-binary-that-does-not-exist-'.bin2hex(random_bytes(3))])['available']);
    }

    public function test_git_warnings_on_stderr_are_never_counted(): void
    {
        $fake = [PHP_BINARY, dirname(__DIR__).'/Support/bin/fake-git.php'];

        putenv('FAKE_GIT_STDOUT= M a.txt\\0?? b c.txt\\0');
        putenv("FAKE_GIT_STDERR=warning: in the working copy of 'a.txt', CRLF will be replaced by LF\nwarning: another one\n");
        putenv('FAKE_GIT_EXIT=0');
        $this->assertSame(['available' => true, 'dirty' => true, 'paths' => 2, 'error' => null], primeclassy_git_dirty($this->tmp, $fake));

        putenv('FAKE_GIT_STDOUT=R  new.txt\\0old.txt\\0');
        $this->assertSame(1, primeclassy_git_dirty($this->tmp, $fake)['paths'], 'rename counts once, original path skipped');

        putenv('FAKE_GIT_STDOUT=warning: this is not a porcelain record\\0');
        $this->assertFalse(primeclassy_git_dirty($this->tmp, $fake)['available'], 'garbage on stdout is unavailable, not dirty/clean');

        putenv('FAKE_GIT_STDOUT=');
        putenv('FAKE_GIT_EXIT=128');
        $this->assertFalse(primeclassy_git_dirty($this->tmp, $fake)['available'], 'non-zero exit');
    }
}
