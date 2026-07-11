<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * Compliance/visibility weight of an audited action.
 *
 * High-sensitivity events (privileged actions, impersonation, exports, destructive
 * operations) are retained longest, surfaced to the affected tenant, and highlighted
 * in the "what admins did" view. This is a classification, not a separate system.
 */
enum Sensitivity: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';

    public function atLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }

    private function weight(): int
    {
        return match ($this) {
            self::Low    => 0,
            self::Normal => 1,
            self::High   => 2,
        };
    }
}
