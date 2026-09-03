<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Config\ScheduleSetBuilder;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Schedule\BackupScheduleType;

/**
 * A drill schedule has to say WHICH restore path it proves.
 *
 * Without it an installation running both a frequent logical drill and an occasional point-in-time
 * one has no way to express the difference, and the runner picks the newest restorable artifact —
 * which is always the logical dump. The point-in-time schedule would fire on its cron, restore a
 * dump, and report green for a WAL chain it never touched.
 */
final class DrillScheduleKindTest extends TestCase
{
    public function testDrillsDefaultToTheLogicalPath(): void
    {
        $entries = (new ScheduleSetBuilder())->drill('0 4 * * *')->entries();

        self::assertSame(BackupKind::LogicalFull, $entries[0]['kind']);
        self::assertSame(BackupScheduleType::Drill, $entries[0]['type']);
    }

    public function testADrillCanTargetThePointInTimePath(): void
    {
        $entries = (new ScheduleSetBuilder())
            ->drill('0 4 * * *', name: 'restore-drill-logical')
            ->drill('0 5 * * 0', name: 'restore-drill-pitr', kind: BackupKind::PhysicalBase)
            ->entries();

        self::assertCount(2, $entries);
        self::assertSame('restore-drill-logical', $entries[0]['name']);
        self::assertSame(BackupKind::LogicalFull, $entries[0]['kind']);
        self::assertSame('restore-drill-pitr', $entries[1]['name']);
        self::assertSame(BackupKind::PhysicalBase, $entries[1]['kind']);
    }

    public function testTheKindMayBeGivenAsAString(): void
    {
        $entries = (new ScheduleSetBuilder())->drill('0 5 * * 0', kind: 'physical_base')->entries();

        self::assertSame(BackupKind::PhysicalBase, $entries[0]['kind']);
    }

    /**
     * Retention derivation keys off the first BACKUP entry's cron. Adding a physical-base backup
     * after the logical one must not move that anchor, or the derived hourly retention silently
     * changes with a line that had nothing to do with it.
     */
    public function testAddingABaseBackupDoesNotMoveTheCadenceAnchor(): void
    {
        $builder = (new ScheduleSetBuilder())
            ->backup('0 */12 * * *', kind: 'logical_full')
            ->backup('0 2 * * 0', kind: 'physical_base');

        self::assertSame('0 */12 * * *', $builder->firstBackupCron());
    }
}
