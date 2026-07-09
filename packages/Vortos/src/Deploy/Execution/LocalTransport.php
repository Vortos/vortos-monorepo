<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

/**
 * A {@see SshTransportInterface} that executes LOCALLY — the transport for the deploy-in-image
 * topology, where the deploy runs as an on-box one-shot container rather than over SSH from CI.
 *
 * There is no remote host to reach: commands run in-process via the {@see CommandRunnerInterface}
 * (Docker is reached through the DOCKER_HOST socket-proxy the one-shot already targets, inherited by
 * the child process), and "copy" is a local filesystem write to a path the one-shot bind-mounts from
 * the host. This lets ReconcileEdge converge the edge compose on the box without any SSH — the same
 * durability the remote-SSH topology already had, now available in-image. Port forwarding is a no-op:
 * the admin API is reached directly over the shared network (edge:2019), never a forwarded loopback.
 */
final class LocalTransport implements SshTransportInterface
{
    public function __construct(
        private readonly CommandRunnerInterface $runner,
    ) {}

    public function run(RemoteCommand $command): CommandResult
    {
        // workingDir is unused: every caller passes absolute paths. Docker/CLI env (DOCKER_HOST, …) is
        // inherited from the one-shot process, so docker compose reaches the socket-proxy.
        return $this->runner->run($command->argv, $command->stdin);
    }

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void
    {
        $dir = \dirname($remotePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory for local copy: %s', $dir));
        }

        if (!@copy($localPath, $remotePath)) {
            throw new \RuntimeException(sprintf('Local copy failed: %s -> %s', $localPath, $remotePath));
        }

        chmod($remotePath, (int) octdec($mode));
    }

    public function openLocalForward(int $remotePort): int
    {
        // No forwarding in-image — callers reach the service directly on the shared network.
        return $remotePort;
    }

    public function closeLocalForward(int $localPort, int $remotePort): void
    {
        // No-op: nothing was forwarded.
    }
}
