<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Reclaim;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Reclaim\Schedule\ImageGcSchedule;
use Vortos\Deploy\Reclaim\Schedule\ReclaimImagesCommand;
use Vortos\Scheduler\Schedule\ScheduleSource;

/**
 * Guards the exact invariants StaticSchedulePass enforces at container-build time, so a broken
 * schedule fails here (fast unit) rather than as a container-compile error on the box.
 */
final class ImageGcScheduleTest extends TestCase
{
    public function test_build_yields_a_valid_system_static_schedule(): void
    {
        $schedule = ImageGcSchedule::build();

        $this->assertSame(ScheduleSource::Static, $schedule->source, 'must be a static schedule');
        $this->assertNull($schedule->tenantId, 'static schedules are system-scoped');
        $this->assertSame(ImageGcSchedule::SCHEDULE_NAME, $schedule->name);
        $this->assertSame(ImageGcSchedule::SCHEDULE_ID, $schedule->id->toString());
        $this->assertSame(ReclaimImagesCommand::class, $schedule->command->commandClass);
        $this->assertFalse($schedule->sensitive);
    }

    public function test_recurring_trigger_yields_a_future_run(): void
    {
        $schedule = ImageGcSchedule::build();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->assertNotNull(
            $schedule->trigger->nextRunAfter($now),
            'a recurring trigger must always yield a future fire',
        );
    }
}
