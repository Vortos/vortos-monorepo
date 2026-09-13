<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

/** The outcome of {@see ProcessLauncher::run()}. Output is already redacted of every declared secret. */
final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public int $durationMs,
        public bool $timedOut,
        public bool $outputTruncated,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }
}
