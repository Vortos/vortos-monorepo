<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Config\BackupConfig;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Schedule\BackupScheduleType;

final class BackupConfigTest extends TestCase
{
    public function test_builds_typed_schedule_set_bound_to_engine_and_env(): void
    {
        $config = BackupConfig::create()
            ->engine('postgres')
            ->environment('production')
            ->store('object-store')->keyPrefix('backups')
            ->schedule(fn ($s) => $s
                ->backup('0 */6 * * *', kind: 'logical_full')
                ->retention('0 3 * * *')
                ->drill('0 4 * * 0'));

        $schedules = $config->buildSchedules();

        $this->assertCount(3, $schedules);
        $this->assertSame(DatabaseEngine::Postgres, $schedules[0]->engine);
        $this->assertSame('production', $schedules[0]->environment);
        $this->assertSame(BackupScheduleType::Backup, $schedules[0]->type);
        $this->assertSame(BackupKind::LogicalFull, $schedules[0]->kind);
        $this->assertSame(BackupScheduleType::Retention, $schedules[1]->type);
        $this->assertSame(BackupScheduleType::Drill, $schedules[2]->type);
        $this->assertSame('object-store', $config->storeKeyValue());
        $this->assertSame('backups', $config->keyPrefixValue());
    }

    public function test_unknown_engine_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupConfig::create()->engine('cassandra');
    }

    public function test_build_schedules_without_engine_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BackupConfig::create()
            ->schedule(fn ($s) => $s->backup('0 3 * * *'))
            ->buildSchedules();
    }

    public function test_explicit_retention_hourly_is_honoured(): void
    {
        $config = BackupConfig::create()
            ->engine('postgres')
            ->schedule(fn ($s) => $s->backup('0 */6 * * *'))
            ->retention(fn ($r) => $r->hourly(8)->daily(7));

        $policy = $config->buildRetentionPolicy();

        $this->assertSame(8, $policy->hourly);
        $this->assertSame(7, $policy->daily);
    }

    public function test_hourly_is_derived_from_sub_daily_cadence_when_unset(): void
    {
        // Every 6h → ~2 days of restore points ⇒ ceil(48/6)=8.
        $config = BackupConfig::create()
            ->engine('postgres')
            ->schedule(fn ($s) => $s->backup('0 */6 * * *'));

        $this->assertSame(8, $config->buildRetentionPolicy()->hourly);
    }

    public function test_hourly_not_derived_for_daily_cadence(): void
    {
        $config = BackupConfig::create()
            ->engine('postgres')
            ->schedule(fn ($s) => $s->backup('0 3 * * *'));

        // Daily cadence: no hourly bucket, default 0 retained.
        $this->assertSame(0, $config->buildRetentionPolicy()->hourly);
    }
}
