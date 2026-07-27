<?php

declare(strict_types=1);

namespace Vortos\Backup\Doctor;

/** Outcome of {@see ReplicationAccessInspector}. */
final readonly class ReplicationAccessFinding
{
    private function __construct(
        public bool $applicable,
        public bool $satisfied,
        public string $message,
        public string $remediation = '',
    ) {}

    public static function satisfied(string $message): self
    {
        return new self(true, true, $message);
    }

    public static function failed(string $message, string $remediation): self
    {
        return new self(true, false, $message, $remediation);
    }

    public static function notApplicable(string $message): self
    {
        return new self(false, true, $message);
    }

    /** A failure only counts when the check applies — see the inspector for why that matters. */
    public function isFailure(): bool
    {
        return $this->applicable && !$this->satisfied;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applicable' => $this->applicable,
            'satisfied' => $this->satisfied,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
