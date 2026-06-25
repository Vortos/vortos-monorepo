<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use InvalidArgumentException;

final readonly class EscalationTier
{
    public function __construct(
        public int $tier,
        public int $waitSeconds,
    ) {
        if ($tier < 0) {
            throw new InvalidArgumentException('EscalationTier tier must be >= 0.');
        }
        if ($waitSeconds < 0) {
            throw new InvalidArgumentException('EscalationTier waitSeconds must be >= 0.');
        }
    }
}
