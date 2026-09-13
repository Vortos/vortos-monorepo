<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use InvalidArgumentException;

final readonly class RecoveryObjectives
{
    public function __construct(
        public int $rpoSeconds,
        public int $rtoSeconds,
    ) {
        // At least one second, not zero. No WAL archive can deliver a zero RPO and no restore a zero
        // RTO, so a zero objective is not strict — it is an alarm that pages forever and gets muted.
        if ($rpoSeconds < 1) {
            throw new InvalidArgumentException('RPO must be >= 1 second.');
        }
        if ($rtoSeconds < 1) {
            throw new InvalidArgumentException('RTO must be >= 1 second.');
        }
    }

    public function rtoMilliseconds(): int
    {
        return $this->rtoSeconds * 1000;
    }

    public function rtoExceeded(int $actualRtoMs): bool
    {
        return $actualRtoMs > ($this->rtoSeconds * 1000);
    }
}
