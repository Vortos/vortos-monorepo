<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final class CredentialLease
{
    private bool $closed = false;

    /** @var list<SecretValue> */
    private array $secrets;

    /** @var list<string> */
    private array $ephemeralPaths;

    /**
     * @param list<SecretValue> $secrets        All secret values to wipe on close
     * @param list<string>      $ephemeralPaths All ephemeral file/agent paths to unlink on close
     */
    public function __construct(
        private readonly CredentialUse $use,
        array $secrets = [],
        array $ephemeralPaths = [],
    ) {
        $this->secrets = $secrets;
        $this->ephemeralPaths = $ephemeralPaths;
    }

    /**
     * @template T
     * @param callable(CredentialUse): T $fn
     * @return T
     */
    public function use(callable $fn): mixed
    {
        if ($this->closed) {
            throw new \LogicException('CredentialLease already closed.');
        }

        try {
            return $fn($this->use);
        } finally {
            $this->wipe();
        }
    }

    public function wipe(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->secrets as $secret) {
            $secret->wipe();
        }

        foreach ($this->ephemeralPaths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->wipe();
    }
}
