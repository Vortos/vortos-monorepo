<?php

declare(strict_types=1);

namespace Vortos\Audit\Integrity;

/**
 * Outcome of walking and verifying one chain (or a segment of one).
 */
final readonly class ChainVerificationResult
{
    private function __construct(
        public bool    $valid,
        public int     $verifiedCount,
        public ?int    $brokenSequence,
        public ?string $reason,
    ) {}

    public static function ok(int $verifiedCount): self
    {
        return new self(true, $verifiedCount, null, null);
    }

    public static function broken(int $verifiedCount, int $brokenSequence, string $reason): self
    {
        return new self(false, $verifiedCount, $brokenSequence, $reason);
    }
}
