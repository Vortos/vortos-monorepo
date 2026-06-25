<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Schedule;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\CronFragmentGenerator;

final class ScheduleTest extends TestCase
{
    public function test_valid_schedule(): void
    {
        $s = new BackupSchedule('nightly-pg', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod', '0 2 * * *');
        $this->assertSame('nightly-pg', $s->name);
    }

    public function test_rejects_bad_cron(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupSchedule('x', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod', '0 2 * *');
    }

    public function test_rejects_bad_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupSchedule('Bad Name', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod', '0 2 * * *');
    }

    public function test_registry_rejects_duplicate_names(): void
    {
        $registry = new BackupScheduleRegistry();
        $registry->add(new BackupSchedule('a', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod', '0 2 * * *'));
        $this->expectException(InvalidArgumentException::class);
        $registry->add(new BackupSchedule('a', DatabaseEngine::Mongo, BackupKind::MongoArchive, 'prod', '0 3 * * *'));
    }

    public function test_cron_fragment_is_managed_and_invokes_backup_run(): void
    {
        $registry = new BackupScheduleRegistry([
            new BackupSchedule('nightly-pg', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod', '0 2 * * *'),
            new BackupSchedule('weekly-base', DatabaseEngine::Postgres, BackupKind::PhysicalBase, 'prod', '0 3 * * 0'),
        ]);
        $fragment = (new CronFragmentGenerator('/usr/local/bin/vortos'))->generate($registry->all());

        $this->assertStringContainsString('# <vortos-backup-schedules>', $fragment);
        $this->assertStringContainsString('# </vortos-backup-schedules>', $fragment);
        $this->assertStringContainsString('backup:run --engine=postgres --kind=logical_full --env=prod', $fragment);
        $this->assertStringContainsString('0 3 * * 0 /usr/local/bin/vortos backup:run --engine=postgres --kind=physical_base', $fragment);
    }

    public function test_fragment_is_regenerable_identically(): void
    {
        $registry = new BackupScheduleRegistry([
            new BackupSchedule('b', DatabaseEngine::Mongo, BackupKind::MongoArchive, 'prod', '0 4 * * *'),
            new BackupSchedule('a', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'prod', '0 2 * * *'),
        ]);
        $gen = new CronFragmentGenerator();

        $this->assertSame($gen->generate($registry->all()), $gen->generate($registry->all()));
    }
}
