<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The default wall-clock. Tests inject a fixed clock so retention/age logic is
 * deterministic.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
