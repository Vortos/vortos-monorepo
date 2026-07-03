<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

/**
 * Per-deploy holder for the resolved SSH connection. In the push delivery model the
 * runner resolves the target's {@see SshConnectionConfig} at the start of a deploy —
 * static settings from the definition joined with the just-issued credential's identity
 * file — and activates it here. {@see LazySshTransport} then reads it on first use, so
 * the transport wiring is static while the socket details are runtime.
 */
final class DeployConnectionContext
{
    private ?SshConnectionConfig $config = null;

    public function activate(SshConnectionConfig $config): void
    {
        $this->config = $config;
    }

    public function deactivate(): void
    {
        $this->config = null;
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }

    public function config(): SshConnectionConfig
    {
        return $this->config
            ?? throw new \RuntimeException(
                'No SSH connection is active. In the push delivery model the deploy runner must '
                . 'resolve and activate the connection before any remote operation runs.'
            );
    }
}
