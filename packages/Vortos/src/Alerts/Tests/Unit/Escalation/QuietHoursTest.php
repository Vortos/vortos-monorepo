<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Escalation;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Escalation\QuietHours;

final class QuietHoursTest extends TestCase
{
    public function test_simple_window_is_quiet_inside_and_not_outside(): void
    {
        $window = new QuietHours('r1', 9, 17);

        self::assertTrue($window->isQuietAt(new DateTimeImmutable('2026-01-01T10:00:00+00:00')));
        self::assertFalse($window->isQuietAt(new DateTimeImmutable('2026-01-01T20:00:00+00:00')));
    }

    public function test_window_wrapping_midnight(): void
    {
        $window = new QuietHours('r1', 22, 7);

        self::assertTrue($window->isQuietAt(new DateTimeImmutable('2026-01-01T23:00:00+00:00')));
        self::assertTrue($window->isQuietAt(new DateTimeImmutable('2026-01-01T03:00:00+00:00')));
        self::assertFalse($window->isQuietAt(new DateTimeImmutable('2026-01-01T12:00:00+00:00')));
    }

    public function test_zero_width_window_is_never_quiet(): void
    {
        $window = new QuietHours('r1', 5, 5);

        self::assertFalse($window->isQuietAt(new DateTimeImmutable('2026-01-01T05:30:00+00:00')));
    }
}
