<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\DR\ArchiverStatus;
use Vortos\Backup\DR\ArchiverStatusReaderInterface;
use Vortos\Backup\DR\RecoveryObjectives;
use Vortos\Backup\DR\RecoveryObjectivesInspector;
use Vortos\Backup\Health\RecoveryPointObjectiveProbe;
use Vortos\Backup\Health\RecoveryTimeObjectiveProbe;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;
use Vortos\Backup\Tests\Support\InMemoryDrillReportStore;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\Health\Testing\HealthProbeConformanceTestCase;

/**
 * Only a probe that reports Fail pages: HealthProbeAlertSource samples `status === Fail`. So the
 * mapping from objective status to probe status IS the alerting contract, and it is pinned here.
 */
final class RecoveryObjectiveProbesTest extends TestCase
{
    public function testABreachedRpoFails(): void
    {
        $probe = new RecoveryPointObjectiveProbe(RecoveryProbeFixtures::inspector(archiverAgeSeconds: 900));

        $result = $probe->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('rpo_breached', $result->errorCode);
    }

    public function testAMetRpoPasses(): void
    {
        self::assertSame(ProbeStatus::Pass, (new RecoveryPointObjectiveProbe(RecoveryProbeFixtures::inspector(archiverAgeSeconds: 30)))->check()->status);
    }

    /** No base backup and no drill: unmeasured. A warning, never a pass and never a page. */
    public function testAnUnmeasurableRtoWarns(): void
    {
        $result = (new RecoveryTimeObjectiveProbe(RecoveryProbeFixtures::inspector(archiverAgeSeconds: 30)))->check();

        self::assertSame(ProbeStatus::Warn, $result->status);
        self::assertSame('rto_indeterminate', $result->errorCode);
    }

    /**
     * Warn, not pass (nothing was measured) and not ProbeResult::skipped(), which carries status Fail
     * and would page an installation for having no backup configuration.
     */
    public function testUndeclaredObjectivesWarnWithoutPaging(): void
    {
        $inspector = RecoveryProbeFixtures::inspector(archiverAgeSeconds: 30, objectives: null);

        $point = (new RecoveryPointObjectiveProbe($inspector))->check();
        $time = (new RecoveryTimeObjectiveProbe($inspector))->check();

        self::assertSame(ProbeStatus::Warn, $point->status);
        self::assertSame('rpo_undeclared', $point->errorCode);
        self::assertSame(ProbeStatus::Warn, $time->status);
        self::assertSame('rto_undeclared', $time->errorCode);
    }
}

final class RecoveryPointObjectiveProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        return new RecoveryPointObjectiveProbe(RecoveryProbeFixtures::inspector(archiverAgeSeconds: 30));
    }

    protected function expectedKind(): ProbeKind
    {
        // Never Readiness: a missed objective pages someone; it must not drop a colour from the edge.
        return ProbeKind::Monitoring;
    }

    protected function expectedKey(): string
    {
        return RecoveryPointObjectiveProbe::NAME;
    }
}

final class RecoveryTimeObjectiveProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        return new RecoveryTimeObjectiveProbe(RecoveryProbeFixtures::inspector(archiverAgeSeconds: 30));
    }

    protected function expectedKind(): ProbeKind
    {
        return ProbeKind::Monitoring;
    }

    protected function expectedKey(): string
    {
        return RecoveryTimeObjectiveProbe::NAME;
    }
}

final class RecoveryProbeFixtures
{
    public static function inspector(int $archiverAgeSeconds, ?RecoveryObjectives $objectives = new RecoveryObjectives(300, 1800)): RecoveryObjectivesInspector
    {
        $now = new DateTimeImmutable('2026-09-13 15:40:00 UTC');

        $archiver = new class ($now->modify("-{$archiverAgeSeconds} seconds")) implements ArchiverStatusReaderInterface {
            public function __construct(private readonly DateTimeImmutable $lastArchivedAt) {}

            public function read(): ArchiverStatus
            {
                return new ArchiverStatus('0000000100000000000005E7', $this->lastArchivedAt, null, null, '0000000100000000000005E8');
            }
        };

        $wal = new class implements WalVolumeReadModelInterface {
            public function walVolumeSince(DatabaseEngine $engine, string $environment, DateTimeImmutable $from): array
            {
                return ['segments' => 0, 'bytes' => 0];
            }

            public function newestWalSegmentName(DatabaseEngine $engine, string $environment): ?string
            {
                return null;
            }
        };

        return new RecoveryObjectivesInspector(
            $objectives,
            $archiver,
            new InMemoryCatalogRepository(),
            $wal,
            new InMemoryDrillReportStore(),
            new BackupScheduleRegistry([]),
            new FixedClock($now),
            'production',
        );
    }
}
