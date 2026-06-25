<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacDriftReport
{
    public function __construct(
        public bool $hasDrift,
        public string $summary,
        public bool $unreachable = false,
    ) {}

    public static function clean(): self
    {
        return new self(false, 'No infrastructure drift detected.');
    }

    public static function drifted(string $summary): self
    {
        return new self(true, $summary);
    }

    public static function unreachable(string $reason): self
    {
        return new self(true, $reason, true);
    }
}
