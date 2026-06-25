<?php

declare(strict_types=1);

namespace Vortos\Auth\Audit\Integrity;

final readonly class ChainVerificationResult
{
    private function __construct(
        public bool $intact,
        public ?int $brokenSequence,
        public ?string $expectedHash,
        public ?string $actualHash,
        public ?string $reason,
    ) {}

    public static function intact(): self
    {
        return new self(true, null, null, null, null);
    }

    public static function broken(int $sequence, string $expectedHash, string $actualHash, string $reason): self
    {
        return new self(false, $sequence, $expectedHash, $actualHash, $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'intact' => $this->intact,
            'broken_sequence' => $this->brokenSequence,
            'expected_hash' => $this->expectedHash,
            'actual_hash' => $this->actualHash,
            'reason' => $this->reason,
        ];
    }
}
