<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Process;

final readonly class ProcessOutput
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
