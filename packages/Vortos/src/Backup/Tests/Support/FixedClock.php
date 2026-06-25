<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** @internal deterministic clock for tests */
final class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
