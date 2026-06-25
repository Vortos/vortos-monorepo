<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\DR;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\DR\DrRunbookGenerator;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\Drill\DrillReport;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;

final class DrRunbookGeneratorTest extends TestCase
{
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
