<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight;

/**
 * Stable grouping for preflight findings. Findings are sorted by category (in this
 * declared order) then id, so the machine-readable report is byte-stable across runs.
 */
enum PreflightCategory: string
{
    case DriverSet = 'driver_set';
    case Capability = 'capability';
    case Credential = 'credential';
    case Arch = 'arch';
    case Schema = 'schema';
    case Plan = 'plan';
    case Security = 'security';

    /** Lower sorts first — drives deterministic finding order. */
    public function sortOrder(): int
    {
        return match ($this) {
            self::DriverSet => 0,
            self::Capability => 1,
            self::Credential => 2,
            self::Arch => 3,
            self::Schema => 4,
            self::Plan => 5,
            self::Security => 6,
        };
    }
}
