<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DR;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\DR\DrRunbookGenerator;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;

final class DrRunbookGeneratorTest extends TestCase
{
    /**
     * The runbook is read during an outage by someone who cannot check its claims, so every statement
     * has to come from live config. These three all used to be fabricated: the key provider was read
     * from an env var nothing sets, the wrap-key variable named in the text was not the one the code
     * uses, and a "daily shallow decrypt-verify" was advertised regardless of what was scheduled.
     */
    public function test_reports_the_schedules_that_are_actually_declared(): void
    {
        $runbook = $this->generator(schedules: [
            $this->schedule('platform-database-backup', '0 */6 * * *', BackupScheduleType::Backup),
            $this->schedule('platform-restore-drill', '0 4 * * 0', BackupScheduleType::Drill),
        ])->generate('prod');

        $this->assertStringContainsString('0 */6 * * *', $runbook);
        $this->assertStringContainsString('0 4 * * 0', $runbook);
        $this->assertStringNotContainsString('Daily shallow decrypt-verify', $runbook);
    }

    public function test_warns_loudly_when_no_restore_drill_is_scheduled(): void
    {
        $runbook = $this->generator(schedules: [
            $this->schedule('platform-database-backup', '0 */6 * * *', BackupScheduleType::Backup),
        ])->generate('prod');

        $this->assertStringContainsString('no restore drill is scheduled', $runbook);
    }

    public function test_says_plainly_when_backups_are_not_encrypted(): void
    {
        $runbook = $this->generator(keyProvider: 'none')->generate('prod');

        $this->assertStringContainsString('NOT encrypted', $runbook);
        $this->assertStringNotContainsString('Unwrap identity', $runbook);
    }

    /** Custody is a fact about this host, not a claim to repeat from a template. */
    public function test_custody_reflects_whether_the_identity_is_present_on_this_host(): void
    {
        $var = 'VORTOS_TEST_IDENTITY_' . bin2hex(random_bytes(4));

        $absent = $this->generator(identityEnvVar: $var)->generate('prod');
        $this->assertStringContainsString('NOT on this host', $absent);

        $_ENV[$var] = 'AGE-SECRET-KEY-1TEST';
        try {
            $present = $this->generator(identityEnvVar: $var)->generate('prod');
            $this->assertStringContainsString('PRESENT on this host', $present);
        } finally {
            unset($_ENV[$var]);
        }
    }

    public function test_restore_steps_send_the_operator_to_a_new_database(): void
    {
        $runbook = $this->generator()->generate('prod');

        $this->assertStringContainsString('--destination', $runbook);
        $this->assertStringContainsString('NEW database', $runbook);
        // The old text pointed at the drill DSN, which is not what a restore targets.
        $this->assertStringNotContainsString('VORTOS_BACKUP_DRILL_DSN', $runbook);
    }

    /** @param list<BackupSchedule> $schedules */
    private function generator(
        array $schedules = [],
        string $keyProvider = 'age',
        string $identityEnvVar = 'VORTOS_BACKUP_AGE_IDENTITY',
    ): DrRunbookGenerator {
        return new DrRunbookGenerator(
            new RecoveryObjectives(300, 1800),
            null,
            new InMemoryDrillReportStore(),
            'object-store',
            null,
            $keyProvider,
            $schedules,
            new RetentionPolicy(hourly: 8, daily: 7, weekly: 4, monthly: 6, maxAgeDays: 90),
            $identityEnvVar,
        );
    }

    private function schedule(string $name, string $cron, BackupScheduleType $type): BackupSchedule
    {
        return new BackupSchedule(
            name: $name,
            engine: DatabaseEngine::Postgres,
            kind: BackupKind::LogicalFull,
            environment: 'prod',
            cron: $cron,
            type: $type,
        );
    }

    public function test_renders_all_required_sections(): void
    {
        $reportStore = new InMemoryDrillReportStore();
        $reportStore->save(new DrillReport(
            'drill-1', DatabaseEngine::Postgres, 'prod', 'artifact-1',
            new DateTimeImmutable('2026-06-24'), 15000, 'passed', [],
        ));

        $generator = new DrRunbookGenerator(
            new RecoveryObjectives(300, 1800),
            new ObjectLockPolicy('compliance', 30),
            $reportStore,
            'object-store',
            'secondary-store',
            'age',
        );

        $runbook = $generator->generate('prod');

        $this->assertStringContainsString('# DR Runbook', $runbook);
        $this->assertStringContainsString('RPO', $runbook);
        $this->assertStringContainsString('RTO', $runbook);
        $this->assertStringContainsString('15000ms', $runbook);
        $this->assertStringContainsString('object-store', $runbook);
        $this->assertStringContainsString('secondary-store', $runbook);
        $this->assertStringContainsString('VORTOS_BACKUP_AGE_IDENTITY', $runbook);
        $this->assertStringContainsString('compliance', $runbook);
        $this->assertStringContainsString('30 days', $runbook);
        $this->assertStringContainsString('backup:restore', $runbook);
        $this->assertStringContainsString('backup:drill', $runbook);
    }

    public function test_identity_value_never_appears(): void
    {
        $generator = new DrRunbookGenerator(
            new RecoveryObjectives(300, 1800),
            null,
            new InMemoryDrillReportStore(),
            'object-store',
            null,
            'age',
        );

        $runbook = $generator->generate('prod');

        // The env var name appears, but not a fake key value
        $this->assertStringContainsString('VORTOS_BACKUP_AGE_IDENTITY', $runbook);
        $this->assertStringNotContainsString('AGE-SECRET-KEY', $runbook);
    }

    public function test_rto_exceeded_warning(): void
    {
        $reportStore = new InMemoryDrillReportStore();
        $reportStore->save(new DrillReport(
            'drill-1', DatabaseEngine::Postgres, 'prod', 'a-1',
            new DateTimeImmutable('2026-06-24'), 2000000, 'passed', [],
        ));

        $generator = new DrRunbookGenerator(
            new RecoveryObjectives(300, 1800),
            null,
            $reportStore,
            'object-store',
            null,
            'age',
        );

        $runbook = $generator->generate('prod');
        $this->assertStringContainsString('WARNING', $runbook);
    }
}
