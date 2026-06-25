<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

final readonly class ProcessOutcome
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $durationMs,
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }
}
