<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

final readonly class ExecResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    /** The last lines of what the command said, for an error message. */
    public function tail(int $lines = 20): string
    {
        $text = trim($this->stderr) !== '' ? $this->stderr : $this->stdout;
        $all = preg_split('/\R/', trim($text)) ?: [];

        return implode("\n", \array_slice($all, -$lines));
    }
}
