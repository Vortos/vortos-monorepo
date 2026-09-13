<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

/** How a {@see RunningProcess} ended. Captured streams are redacted and capped to their tail. */
final readonly class ProcessExit
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
