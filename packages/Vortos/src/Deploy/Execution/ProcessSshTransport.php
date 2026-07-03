<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

/**
 * The concrete SSH transport for the push delivery model: it shells out to ssh/scp
 * through the shared {@see CommandRunnerInterface} (the single audited exec seam), using
 * the host-key-verifying options from {@see SshConnectionConfig}. Host-key checking is
 * always strict (StrictHostKeyChecking=yes + an explicit known_hosts) — there is no
 * insecure fallback.
 *
 * Connection reuse and port-forwarding ride on OpenSSH's ControlMaster multiplexing:
 * the first run() opens a master socket (ControlPath), later commands reuse it, and
 * {@see openLocalForward()} adds/removes forwards on that master via ssh -O forward.
 */
final class ProcessSshTransport implements SshTransportInterface
{
    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly SshConnectionConfig $config,
        private readonly ?float $timeoutSeconds = 300.0,
    ) {}

    public function run(RemoteCommand $command): CommandResult
    {
        $remote = $this->remoteCommandString($command);

        $argv = ['ssh', ...$this->config->toSshOptions(), $this->config->destination(), '--', 'sh', '-c', $remote];

        return $this->runner->run($argv, $command->stdin, $this->timeoutSeconds);
    }

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void
    {
        // scp options mirror ssh options but use -P (uppercase) for the port.
        $scpArgv = ['scp', ...$this->scpOptions(), $localPath, sprintf('%s:%s', $this->config->destination(), $remotePath)];
        $this->runner->run($scpArgv, null, $this->timeoutSeconds)->throwOnFailure('scp upload');

        $chmod = new RemoteCommand(['chmod', $mode, $remotePath]);
        $this->run($chmod)->throwOnFailure('remote chmod');
    }

    public function openLocalForward(int $remotePort): int
    {
        if ($this->config->controlPath === null) {
            throw new \RuntimeException('Local port-forwarding requires a ControlMaster socket (controlPath); none is configured.');
        }

        $localPort = $this->allocateLocalPort();
        $spec = sprintf('%d:127.0.0.1:%d', $localPort, $remotePort);

        // Ensure the master connection exists, then request the forward on it.
        $this->run(new RemoteCommand(['true']))->throwOnFailure('ssh master warmup');

        $argv = ['ssh', ...$this->config->toSshOptions(), '-O', 'forward', '-L', $spec, $this->config->destination()];
        $this->runner->run($argv, null, $this->timeoutSeconds)->throwOnFailure('ssh -O forward');

        return $localPort;
    }

    public function closeLocalForward(int $localPort, int $remotePort): void
    {
        if ($this->config->controlPath === null) {
            return;
        }

        $spec = sprintf('%d:127.0.0.1:%d', $localPort, $remotePort);
        $argv = ['ssh', ...$this->config->toSshOptions(), '-O', 'cancel', '-L', $spec, $this->config->destination()];

        // Best-effort teardown: a failed cancel must not mask a deploy result.
        $this->runner->run($argv, null, $this->timeoutSeconds);
    }

    private function remoteCommandString(RemoteCommand $command): string
    {
        $quoted = array_map(static fn (string $arg): string => escapeshellarg($arg), $command->argv);
        $joined = implode(' ', $quoted);

        if ($command->workingDir !== null) {
            return sprintf('cd %s && %s', escapeshellarg($command->workingDir), $joined);
        }

        return $joined;
    }

    /** @return list<string> */
    private function scpOptions(): array
    {
        $options = [];
        foreach ($this->config->toSshOptions() as $opt) {
            // ssh uses -p for the port; scp uses -P. Rewrite that one flag.
            $options[] = $opt === '-p' ? '-P' : $opt;
        }

        return $options;
    }

    private function allocateLocalPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException(sprintf('Could not allocate a local forward port: %s (%d).', $errstr, $errno));
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if ($name === false || !str_contains($name, ':')) {
            throw new \RuntimeException('Could not determine an allocated local forward port.');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
