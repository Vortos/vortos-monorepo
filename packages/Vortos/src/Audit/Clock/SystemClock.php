<?php

declare(strict_types=1);

namespace Vortos\Audit\Clock;

use Psr\Clock\ClockInterface;

/** Wall-clock. Injected where the module needs "now" so tests can substitute a fixed clock. */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
