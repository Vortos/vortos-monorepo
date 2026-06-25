<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

final readonly class SshConnectionConfig
{
    public function __construct(
        public string $host,
        public string $user,
        public string $identityFile,
        public string $knownHostsFile,
        public int $port = 22,
        public ?string $controlPath = null,
    ) {
        if ($host === '') {
            throw new \InvalidArgumentException('SSH host must not be empty.');
        }

        if ($user === '') {
            throw new \InvalidArgumentException('SSH user must not be empty.');
        }

        if ($identityFile === '') {
            throw new \InvalidArgumentException('SSH identity file must not be empty.');
        }

        if ($knownHostsFile === '') {
            throw new \InvalidArgumentException('SSH known_hosts file must not be empty — host-key verification is mandatory.');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(sprintf('SSH port must be 1-65535, got %d.', $port));
        }
    }

    /** @return list<string> */
    public function toSshOptions(): array
    {
        $options = [
            '-o', 'StrictHostKeyChecking=yes',
            '-o', sprintf('UserKnownHostsFile=%s', $this->knownHostsFile),
            '-i', $this->identityFile,
            '-p', (string) $this->port,
        ];

        if ($this->controlPath !== null) {
            $options[] = '-o';
            $options[] = sprintf('ControlMaster=auto');
            $options[] = '-o';
            $options[] = sprintf('ControlPath=%s', $this->controlPath);
            $options[] = '-o';
            $options[] = 'ControlPersist=60';
        }

        return $options;
    }

    public function destination(): string
    {
        return sprintf('%s@%s', $this->user, $this->host);
    }
}
