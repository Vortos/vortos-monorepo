<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use InvalidArgumentException;
use Vortos\Foundation\Secret\SecretValue;

/**
 * The single, audited process launcher (RC-2).
 *
 * Why one: credentials reached argv (`mongodump --uri=` with the password, readable by any local user
 * via /proc/<pid>/cmdline) and a doctor handed psql the entire application environment, because ~20
 * call sites each called proc_open/exec/Symfony Process their own way and nothing could check them.
 * This class makes the safe path the only path:
 *
 *  - argv is an array, never a shell string (no quoting bugs, no injection);
 *  - a declared secret found in any argv string refuses the launch;
 *  - secrets reach the child by environment, stdin or a 0600 {@see SensitiveFile} only;
 *  - every declared secret is scrubbed (raw, url- and base64-encoded) from all output returned;
 *  - the child environment is an explicit {@see EnvironmentPolicy};
 *  - timeouts terminate (SIGTERM, then SIGKILL after a grace period);
 *  - captured output is capped, and read with select() so neither pipe can deadlock the other.
 */
final class ProcessLauncher implements ProcessLauncherInterface
{
    private const TERMINATE_GRACE_SECONDS = 5.0;

    private const READ_CHUNK_BYTES = 65_536;

    private const MINIMAL_ENVIRONMENT = ['PATH', 'HOME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TMPDIR', 'USER'];

    private const MINIMAL_ENVIRONMENT_WINDOWS = ['SystemRoot', 'SYSTEMROOT', 'PATHEXT', 'COMSPEC', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA'];

    public function run(ProcessSpec $spec, string|SecretValue|null $stdin = null): ProcessResult
    {
        $secrets = LaunchSecrets::for($spec, $stdin);
        $secrets->assertAbsentFromArgv($spec);

        try {
            [$argv, $env] = $secrets->materialise($spec, $this->baseEnvironment($spec->environment));

            $started = hrtime(true);
            $handle = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $spec->cwd, $env, ['bypass_shell' => true]);
            if (!\is_resource($handle)) {
                throw ProcessLaunchException::couldNotStart($spec->program());
            }

            $input = $stdin instanceof SecretValue ? $stdin->reveal() : ($stdin ?? '');
            [$exitCode, $stdout, $stderr, $timedOut, $truncated] = $this->drive($handle, $pipes, $input, $spec);

            return new ProcessResult(
                $exitCode,
                $secrets->redact($stdout),
                $secrets->redact($stderr),
                (int) ((hrtime(true) - $started) / 1_000_000),
                $timedOut,
                $truncated,
            );
        } finally {
            $secrets->cleanup();
        }
    }

    public function start(ProcessSpec $spec, StreamMode $stdin, StreamMode $stdout, StreamMode $stderr): RunningProcess
    {
        if ($spec->timeoutSeconds !== null) {
            throw new InvalidArgumentException('A streaming launch cannot carry a timeout; its consumer owns its duration.');
        }
        if ($stderr === StreamMode::Pipe) {
            throw new InvalidArgumentException('stderr as a pipe can deadlock a caller that is reading stdout; use StreamMode::Capture.');
        }
        if ($stdin === StreamMode::Capture || $stdout === StreamMode::Tty || $stderr === StreamMode::Tty) {
            throw new InvalidArgumentException('Capture applies to output streams and Tty to stdin only.');
        }

        $secrets = LaunchSecrets::for($spec);
        $secrets->assertAbsentFromArgv($spec);

        try {
            [$argv, $env] = $secrets->materialise($spec, $this->baseEnvironment($spec->environment));

            $captures = [];
            $descriptors = [
                0 => $this->descriptor($stdin, 0, $secrets, $captures),
                1 => $this->descriptor($stdout, 1, $secrets, $captures),
                2 => $this->descriptor($stderr, 2, $secrets, $captures),
            ];

            $handle = proc_open($argv, $descriptors, $pipes, $spec->cwd, $env, ['bypass_shell' => true]);
            if (!\is_resource($handle)) {
                throw ProcessLaunchException::couldNotStart($spec->program());
            }
        } catch (\Throwable $e) {
            $secrets->cleanup();

            throw $e;
        }

        return new RunningProcess($handle, $pipes, $captures, $secrets, $spec->program());
    }

    public function which(string $program): ?string
    {
        if ($program === '') {
            return null;
        }

        if (str_contains($program, '/') || str_contains($program, '\\')) {
            return is_file($program) && is_executable($program) ? $program : null;
        }

        $extensions = [''];
        if (\PHP_OS_FAMILY === 'Windows') {
            $extensions = array_merge($extensions, explode(';', strtolower((string) (getenv('PATHEXT') ?: '.exe;.bat;.cmd'))));
        }

        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }
            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $program . $extension;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param resource              $handle
     * @param array<int, resource>  $pipes
     *
     * @return array{int, string, string, bool, bool}
     */
    private function drive(mixed $handle, array $pipes, string $input, ProcessSpec $spec): array
    {
        $deadline = $spec->timeoutSeconds !== null ? microtime(true) + $spec->timeoutSeconds : null;
        $output = [1 => '', 2 => ''];
        $truncated = false;
        $timedOut = false;
        $exitCode = null;

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $written = 0;
        if ($input === '') {
            fclose($pipes[0]);
            unset($pipes[0]);
        }

        while (isset($pipes[0]) || isset($pipes[1]) || isset($pipes[2])) {
            $read = array_values(array_filter([$pipes[1] ?? null, $pipes[2] ?? null]));
            $write = isset($pipes[0]) ? [$pipes[0]] : [];
            $except = null;

            if ($deadline !== null && microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            if (@stream_select($read, $write, $except, 0, 200_000) === false) {
                break;
            }

            foreach ($write as $stream) {
                $chunk = substr($input, $written, self::READ_CHUNK_BYTES);
                $bytes = @fwrite($stream, $chunk);
                if ($bytes === false || $bytes === 0 && feof($stream)) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                    continue;
                }
                $written += $bytes;
                if ($written >= \strlen($input)) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }

            foreach ($read as $stream) {
                $fd = $stream === ($pipes[1] ?? null) ? 1 : 2;
                $data = fread($stream, self::READ_CHUNK_BYTES);
                if ($data !== false && $data !== '') {
                    // Keep draining past the cap so the child is never blocked on a full pipe.
                    $room = $spec->maxOutputBytes - \strlen($output[$fd]);
                    if ($room > 0) {
                        $output[$fd] .= substr($data, 0, $room);
                    }
                    if (\strlen($data) > max(0, $room)) {
                        $truncated = true;
                    }
                }
                if (feof($stream)) {
                    fclose($stream);
                    unset($pipes[$fd]);
                }
            }
        }

        while (true) {
            $status = proc_get_status($handle);
            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if ($timedOut || ($deadline !== null && microtime(true) >= $deadline)) {
                $timedOut = true;
                $this->terminate($handle);
                $status = proc_get_status($handle);
                $exitCode = $status['running'] ? -1 : (int) $status['exitcode'];
                break;
            }
            usleep(10_000);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($handle);

        return [$exitCode, $output[1], $output[2], $timedOut, $truncated];
    }

    /** @param resource $handle */
    private function terminate(mixed $handle): void
    {
        proc_terminate($handle, 15);
        $grace = microtime(true) + self::TERMINATE_GRACE_SECONDS;
        while (microtime(true) < $grace) {
            if (!proc_get_status($handle)['running']) {
                return;
            }
            usleep(50_000);
        }
        proc_terminate($handle, 9);
        usleep(100_000);
    }

    /**
     * @param array<int, string> $captures
     *
     * @return array<int, mixed>|resource
     */
    private function descriptor(StreamMode $mode, int $fd, LaunchSecrets $secrets, array &$captures): mixed
    {
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        return match ($mode) {
            StreamMode::Null => ['file', $null, $fd === 0 ? 'r' : 'w'],
            StreamMode::Pipe => ['pipe', $fd === 0 ? 'r' : 'w'],
            StreamMode::Inherit => match ($fd) {
                0 => \STDIN,
                1 => \STDOUT,
                default => \STDERR,
            },
            StreamMode::Tty => \PHP_OS_FAMILY === 'Windows'
                ? throw ProcessLaunchException::unsupported('A controlling terminal is not available on Windows.')
                : ['file', '/dev/tty', 'r'],
            StreamMode::Capture => ['file', $captures[$fd] = $secrets->captureFile(), 'w'],
        };
    }

    /** @return array<string, string> */
    private function baseEnvironment(EnvironmentPolicy $policy): array
    {
        /** @var array<string, string> $parent */
        $parent = getenv();

        if ($policy === EnvironmentPolicy::Inherit) {
            return $parent;
        }

        $keys = self::MINIMAL_ENVIRONMENT;
        if (\PHP_OS_FAMILY === 'Windows') {
            $keys = array_merge($keys, self::MINIMAL_ENVIRONMENT_WINDOWS);
        }

        return array_intersect_key($parent, array_flip($keys));
    }
}
