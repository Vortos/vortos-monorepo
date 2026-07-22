<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Health;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Health\BackupFreshnessInspector;
use Vortos\Backup\Health\BackupFreshnessProbe;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Testing\HealthProbeConformanceTestCase;

/**
 * Runs the framework's probe conformance suite against {@see BackupFreshnessProbe}.
 *
 * Added after the probe shipped without an `#[AsDriver]` key and failed the deploy's wire-contract
 * gate at boot — `HealthProbeInterface` extends `DriverInterface`, so a tagged probe with no key is a
 * hard container error. The conformance case asserts exactly that (plus name shape, kind, and
 * capability purity), so the gap is now caught by a unit test rather than by a failed deploy.
 *
 * Also pins the kind to MONITORING: promoting this probe to Readiness would let a stale backup fail
 * the blue-green health gate and drop the color from the edge's upstream pool, turning a backup
 * problem into an outage. That must never happen by accident.
 */
final class BackupFreshnessProbeConformanceTest extends HealthProbeConformanceTestCase
{
    protected function createProbe(): HealthProbeInterface
    {
        return new BackupFreshnessProbe($this->inspector());
    }

    protected function expectedKind(): ProbeKind
    {
        return ProbeKind::Monitoring;
    }

    protected function expectedKey(): string
    {
        return 'backup-freshness';
    }

    private function inspector(): BackupFreshnessInspector
    {
        $catalog = new class implements BackupCatalogReadModelInterface {
            public function byId(string $backupId): ?BackupArtifact { return null; }
            public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array { return []; }
            public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact { return null; }
        };

        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-07-22 12:00:00'); }
        };

        return new BackupFreshnessInspector($catalog, new BackupScheduleRegistry(), $clock);
    }
}
