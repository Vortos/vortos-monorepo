<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Psr\Clock\ClockInterface;

/**
 * Default PSR-20 clock. Tests inject a frozen clock so scheduled/ramp evaluation is
 * deterministic; production uses this. All schedule math is done in UTC.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
