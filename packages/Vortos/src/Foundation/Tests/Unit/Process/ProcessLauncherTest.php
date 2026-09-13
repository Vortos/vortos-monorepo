<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests\Unit\Process;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLaunchException;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Process\SensitiveFile;
use Vortos\Foundation\Process\StreamMode;
use Vortos\Foundation\Secret\SecretValue;

/**
 * Real processes, not doubles: the properties that matter — what /proc shows, what the child's
 * environment holds, whether a file is 0600 and gone afterwards — only exist on a real launch.
 *
 * @requires OS Linux
 */
final class ProcessLauncherTest extends TestCase
{
    private const SECRET = 'hunter2-Sup3r:S3cret/pa$$@word';

    private ProcessLauncher $launcher;

    protected function setUp(): void
    {
        $this->launcher = new ProcessLauncher();
    }

    public function testRunsAndCapturesOutputAndExitCode(): void
    {
        $result = $this->launcher->run($this->spec(['sh', '-c', 'printf out; printf err >&2; exit 3']));

        self::assertSame(3, $result->exitCode);
        self::assertSame('out', $result->stdout);
        self::assertSame('err', $result->stderr);
        self::assertFalse($result->isSuccessful());
    }

    public function testPipesStdinIncludingASecretOne(): void
    {
        self::assertSame('plain', $this->launcher->run($this->spec(['cat']), 'plain')->stdout);
        self::assertSame('***', $this->launcher->run($this->spec(['cat']), SecretValue::fromString(self::SECRET))->stdout);
    }

    /** A secret on the command line is readable by every local user; the launch must be refused. */
    public function testRefusesADeclaredSecretOnArgvWithoutEchoingIt(): void
    {
        $spec = new ProcessSpec(
            ['sh', '-c', 'true', '--uri=mongodb://u:' . self::SECRET . '@h/db'],
            EnvironmentPolicy::Minimal,
            5.0,
            env: ['TOKEN' => SecretValue::fromString(self::SECRET)],
        );

        try {
            $this->launcher->run($spec);
            self::fail('launch with a secret on argv must be refused');
        } catch (ProcessLaunchException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringContainsString('argument 3', $e->getMessage());
        }
    }

    public function testASecretValueCannotBeAnArgvElement(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new ProcessSpec(['echo', SecretValue::fromString(self::SECRET)], EnvironmentPolicy::Minimal, 5.0);
    }

    public function testSecretEnvironmentReachesTheChildAndIsRedactedFromOutput(): void
    {
        $result = $this->launcher->run($this->spec(
            ['sh', '-c', 'printf "%s|" "$TOKEN"; printf "%s" "$TOKEN" >&2'],
            env: ['TOKEN' => SecretValue::fromString(self::SECRET)],
        ));

        self::assertSame(0, $result->exitCode);
        self::assertSame('***|', $result->stdout, 'the child received the value, and it was scrubbed on the way back');
        self::assertSame('***', $result->stderr);
    }

    public function testUrlEncodedFormsOfASecretAreRedactedToo(): void
    {
        $result = $this->launcher->run($this->spec(
            ['sh', '-c', 'printf "%s" "$ENCODED"'],
            env: ['ENCODED' => rawurlencode(self::SECRET)],
            redact: [SecretValue::fromString(self::SECRET)],
        ));

        self::assertSame('***', $result->stdout);
    }

    public function testMinimalEnvironmentDoesNotLeakTheParentsSecrets(): void
    {
        putenv('VORTOS_LAUNCHER_TEST_PARENT_SECRET=leaked');
        try {
            $minimal = $this->launcher->run($this->spec(['env']));
            $inherit = $this->launcher->run(new ProcessSpec(['env'], EnvironmentPolicy::Inherit, 5.0));
        } finally {
            putenv('VORTOS_LAUNCHER_TEST_PARENT_SECRET');
        }

        self::assertStringNotContainsString('VORTOS_LAUNCHER_TEST_PARENT_SECRET', $minimal->stdout);
        self::assertStringContainsString('PATH=', $minimal->stdout);
        self::assertStringContainsString('VORTOS_LAUNCHER_TEST_PARENT_SECRET=leaked', $inherit->stdout);
    }

    public function testSensitiveFileIsPrivateCarriesTheSecretAndIsRemovedAfterwards(): void
    {
        $result = $this->launcher->run($this->spec(
            ['sh', '-c', 'printf "%s\n" "${1#--config=}"; stat -c %a "${1#--config=}"; stat -c %a "$(dirname "${1#--config=}")"; cat "${1#--config=}"', 'sh', new SensitiveFile(SecretValue::fromString(self::SECRET), '--config=')],
        ));

        [$path, $fileMode, $dirMode, $contents] = explode("\n", $result->stdout, 4);
        self::assertSame('600', $fileMode);
        self::assertSame('700', $dirMode);
        self::assertSame('***', $contents);
        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist(\dirname($path));
    }

    public function testSensitiveFileCanBeReferencedFromTheEnvironment(): void
    {
        $result = $this->launcher->run($this->spec(
            ['sh', '-c', 'cat "$PGPASSFILE"'],
            env: ['PGPASSFILE' => new SensitiveFile(SecretValue::fromString('*:*:*:*:' . self::SECRET))],
        ));

        self::assertSame('***', $result->stdout, 'the whole file content is the declared secret, so all of it is scrubbed');
    }

    /** The whole point: what any local user sees in /proc for a running child. */
    public function testTheSecretIsNeverOnTheLiveCommandLine(): void
    {
        $process = $this->launcher->start(
            // `sh -c 'sleep 5' sh <arg>`: the extra argument is accepted and the process stays alive —
            // a program that rejected it would exit, and a zombie's cmdline reads empty, proving nothing.
            new ProcessSpec(['sh', '-c', 'sleep 5', 'sh', new SensitiveFile(SecretValue::fromString(self::SECRET), '--config=')], EnvironmentPolicy::Minimal, null),
            StreamMode::Null,
            StreamMode::Null,
            StreamMode::Capture,
        );

        $cmdline = (string) @file_get_contents('/proc/' . $process->pid() . '/cmdline');
        unset($process);

        self::assertNotSame('', $cmdline);
        self::assertStringNotContainsString(self::SECRET, $cmdline);
        self::assertStringContainsString('--config=', $cmdline);
    }

    public function testTimeoutTerminatesTheProcess(): void
    {
        $started = microtime(true);
        $result = $this->launcher->run(new ProcessSpec(['sleep', '30'], EnvironmentPolicy::Minimal, 0.5));

        self::assertTrue($result->timedOut);
        self::assertFalse($result->isSuccessful());
        self::assertLessThan(10.0, microtime(true) - $started);
    }

    /** Large stdin and large output in both streams at once: select() must keep every pipe moving. */
    public function testLargeBidirectionalTrafficDoesNotDeadlockAndOutputIsCapped(): void
    {
        $payload = str_repeat('0123456789abcdef', 512 * 1024); // 8 MiB
        $result = $this->launcher->run(
            new ProcessSpec(['sh', '-c', 'tee /dev/stderr'], EnvironmentPolicy::Minimal, 60.0, maxOutputBytes: 1_048_576),
            $payload,
        );

        self::assertSame(0, $result->exitCode);
        self::assertSame(1_048_576, \strlen($result->stdout));
        self::assertSame(1_048_576, \strlen($result->stderr));
        self::assertTrue($result->outputTruncated);
    }

    public function testStreamingLaunchReportsExitAndRedactedCapturedStderr(): void
    {
        $process = $this->launcher->start(
            new ProcessSpec(['sh', '-c', 'cat; printf "failed for %s" "$TOKEN" >&2; exit 7'], EnvironmentPolicy::Minimal, null, env: ['TOKEN' => SecretValue::fromString(self::SECRET)]),
            StreamMode::Pipe,
            StreamMode::Pipe,
            StreamMode::Capture,
        );

        fwrite($process->stdin(), 'streamed-bytes');
        fclose($process->stdin());
        $read = stream_get_contents($process->stdout());
        $exit = $process->wait();

        self::assertSame('streamed-bytes', $read);
        self::assertSame(7, $exit->exitCode);
        self::assertSame('failed for ***', $exit->stderr);
        self::assertSame($exit, $process->wait(), 'wait() is idempotent');
    }

    public function testStreamingRefusesATimeoutAndAStderrPipe(): void
    {
        try {
            $this->launcher->start(new ProcessSpec(['true'], EnvironmentPolicy::Minimal, 1.0), StreamMode::Null, StreamMode::Pipe, StreamMode::Capture);
            self::fail('timeout must be refused');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $this->launcher->start(new ProcessSpec(['true'], EnvironmentPolicy::Minimal, null), StreamMode::Null, StreamMode::Pipe, StreamMode::Pipe);
    }

    public function testCaptureFilesAreRemovedWhenAProcessIsAbandoned(): void
    {
        $process = $this->launcher->start(new ProcessSpec(['sh', '-c', 'printf "%s" "$1"; sleep 5', 'sh', new SensitiveFile(SecretValue::fromString('x'))], EnvironmentPolicy::Minimal, null), StreamMode::Null, StreamMode::Pipe, StreamMode::Capture);
        $path = (string) fread($process->stdout(), 4096);
        self::assertFileExists($path);

        unset($process);

        self::assertFileDoesNotExist($path);
    }

    public function testWhichResolvesWithoutAShell(): void
    {
        self::assertNotNull($this->launcher->which('sh'));
        self::assertNull($this->launcher->which('vortos-definitely-not-a-program'));
        self::assertNull($this->launcher->which('/etc/passwd'), 'not executable');
    }

    public function testShellMetacharactersAreNotInterpreted(): void
    {
        $result = $this->launcher->run($this->spec(['printf', '%s', '$(id); `id` | ; && rm -rf /']));

        self::assertSame('$(id); `id` | ; && rm -rf /', $result->stdout);
    }

    /**
     * @param non-empty-list<string|SensitiveFile>            $argv
     * @param array<string, string|SecretValue|SensitiveFile> $env
     * @param list<SecretValue>                               $redact
     */
    private function spec(array $argv, array $env = [], array $redact = []): ProcessSpec
    {
        return new ProcessSpec($argv, EnvironmentPolicy::Minimal, 30.0, env: $env, redact: $redact);
    }
}
