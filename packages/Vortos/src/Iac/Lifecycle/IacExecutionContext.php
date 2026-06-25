<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

use Vortos\Secrets\Value\SecretValue;

final readonly class IacExecutionContext
{
    /**
     * @param list<string>               $envAllowlist
     * @param array<string, SecretValue>  $providerCredentials
     */
    public function __construct(
        public int $parallelism = 10,
        public int $timeoutSeconds = 600,
        public array $envAllowlist = [],
        public array $providerCredentials = [],
        public ?string $binaryHint = null,
        public int $lockTimeoutSeconds = 60,
        public bool $allowDestructive = false,
    ) {
        if ($parallelism < 1) {
            throw new \InvalidArgumentException('Parallelism must be at least 1.');
        }
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Timeout must be at least 1 second.');
        }
        if ($lockTimeoutSeconds < 0) {
            throw new \InvalidArgumentException('Lock timeout must be non-negative.');
        }
    }

    public function withAllowDestructive(bool $allow): self
    {
        return new self(
            $this->parallelism,
            $this->timeoutSeconds,
            $this->envAllowlist,
            $this->providerCredentials,
            $this->binaryHint,
            $this->lockTimeoutSeconds,
            $allow,
        );
    }
}
