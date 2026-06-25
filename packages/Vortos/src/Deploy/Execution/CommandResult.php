<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

use Vortos\Deploy\Exception\CommandFailedException;

final readonly class CommandResult
{
    /** @param list<string> $redactTokens */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public float $duration,
        private array $redactTokens = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->exitCode === 0;
    }

    public function throwOnFailure(string $context = ''): self
    {
        if (!$this->isSuccess()) {
            throw CommandFailedException::fromResult($this, $context);
        }

        return $this;
    }

    public function redactedStdout(): string
    {
        return $this->redact($this->stdout);
    }

    public function redactedStderr(): string
    {
        return $this->redact($this->stderr);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'stdout' => $this->redactedStdout(),
            'stderr' => $this->redactedStderr(),
            'duration' => $this->duration,
        ];
    }

    private function redact(string $value): string
    {
        if ($this->redactTokens === []) {
            return $value;
        }

        return str_replace($this->redactTokens, '***', $value);
    }
}
