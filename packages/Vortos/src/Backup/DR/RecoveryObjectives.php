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
        if ($rpoSeconds < 0) {
            throw new InvalidArgumentException('RPO must be >= 0.');
        }
        if ($rtoSeconds < 0) {
            throw new InvalidArgumentException('RTO must be >= 0.');
        }
    }

    public function rtoExceeded(int $actualRtoMs): bool
    {
        return $actualRtoMs > ($this->rtoSeconds * 1000);
    }
}
