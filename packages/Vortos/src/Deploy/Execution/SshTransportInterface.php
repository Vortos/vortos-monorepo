<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

interface SshTransportInterface
{
    public function run(RemoteCommand $command): CommandResult;

    public function copy(string $localPath, string $remotePath, string $mode = '0644'): void;

    /**
     * Open an SSH local port-forward from an ephemeral loopback port on this host to
     * <remotePort> on the remote's loopback, and return the local port. Used to reach a
     * service the remote binds to 127.0.0.1 only (e.g. the Caddy admin API on :2019)
     * without ever exposing it publicly. Returns a port that tunnels to the remote.
     */
    public function openLocalForward(int $remotePort): int;

    public function closeLocalForward(int $localPort, int $remotePort): void;
}
