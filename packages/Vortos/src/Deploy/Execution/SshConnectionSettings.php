<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

/**
 * The static, non-secret coordinates of the deploy target's SSH endpoint — host, user,
 * port. Distinct from {@see \Vortos\Deploy\Definition\DeploymentDefinition::$host}, which
 * is the *driver* key ("ssh-compose"). Combined at deploy time with the issued
 * credential's identity file and a known_hosts file to form a full {@see SshConnectionConfig}.
 */
final readonly class SshConnectionSettings
{
    public function __construct(
        public string $host,
        public string $user,
        public int $port = 22,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('SSH host must not be empty.');
        }

        if ($user === '') {
            throw new \InvalidArgumentException('SSH user must not be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('SSH port must be 1-65535, got %d.', $port));
        }
    }

    public function toConnectionConfig(string $identityFile, string $knownHostsFile, ?string $controlPath = null): SshConnectionConfig
    {
        return new SshConnectionConfig(
            host: $this->host,
            user: $this->user,
            identityFile: $identityFile,
            knownHostsFile: $knownHostsFile,
            port: $this->port,
            controlPath: $controlPath,
        );
    }
}
