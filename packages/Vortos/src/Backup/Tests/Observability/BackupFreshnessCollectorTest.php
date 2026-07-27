<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Observability;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Domain\BackupId;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\SourceRef;
use Vortos\Backup\Observability\BackupFreshnessCollector;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * The case that matters is the one push-based backup alerting cannot see: a backup that stopped
 * running rather than started failing.
 */
final class BackupFreshnessCollectorTest extends TestCase
{
    public function test_reports_age_of_the_newest_backup(): void
    {
        $metrics = new RecordingMetrics();
        $now = new DateTimeImmutable('2026-07-26 12:00:00');

        $this->collector($metrics, $now, [
            'postgres' => $this->artifact($now->modify('-6 hours'), sizeBytes: 1024),
        ])->collect();

        self::assertSame(21600.0, $this->valueFor($metrics, 'backup_last_success_age_seconds', 'postgres'));
        self::assertSame(1.0, $this->valueFor($metrics, 'backup_present', 'postgres'));
        self::assertSame(1024.0, $this->valueFor($metrics, 'backup_last_success_size_bytes', 'postgres'));
    }

    public function test_a_never_backed_up_engine_reports_absence_and_no_age(): void
    {
        $metrics = new RecordingMetrics();

        $this->collector($metrics, new DateTimeImmutable(), [])->collect();

        self::assertSame(0.0, $this->valueFor($metrics, 'backup_present', 'postgres'));
        self::assertNull(
            $this->valueFor($metrics, 'backup_last_success_age_seconds', 'postgres'),
            'Age 0 would read as "just backed up" — the exact inversion of never having run.',
        );
    }

    public function test_a_catalog_failure_does_not_break_collection_for_other_engines(): void
    {
        $metrics = new RecordingMetrics();

        $catalog = new class implements BackupCatalogReadModelInterface {
            public function byId(string $backupId): ?BackupArtifact { return null; }
            public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array { return []; }
            public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact
            {
                if ($engine === DatabaseEngine::Postgres) {
                    throw new \RuntimeException('catalog table unavailable');
                }

                return null;
            }

            public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact
            {
                return $this->latest($engine, $environment);
            }
        };

        (new BackupFreshnessCollector(
            $catalog,
            new FixedClock(new DateTimeImmutable()),
            [DatabaseEngine::Postgres, DatabaseEngine::Mongo],
            'prod',
            new FrameworkTelemetry($metrics),
        ))->collect();

        self::assertSame(0.0, $this->valueFor($metrics, 'backup_present', 'mongo'), 'Mongo must still be reported after the Postgres read threw.');
    }

    public function test_without_metrics_installed_collection_is_a_no_op(): void
    {
        (new BackupFreshnessCollector(
            new FixedCatalog([]),
            new FixedClock(new DateTimeImmutable()),
            [DatabaseEngine::Postgres],
            'prod',
            null,
        ))->collect();

        $this->expectNotToPerformAssertions();
    }

    /** @param array<string, BackupArtifact> $latest */
    private function collector(RecordingMetrics $metrics, DateTimeImmutable $now, array $latest): BackupFreshnessCollector
    {
        return new BackupFreshnessCollector(
            new FixedCatalog($latest),
            new FixedClock($now),
            [DatabaseEngine::Postgres, DatabaseEngine::Mongo],
            'prod',
            new FrameworkTelemetry($metrics),
        );
    }

    private function valueFor(RecordingMetrics $metrics, string $name, string $engine): ?float
    {
        foreach ($metrics->gauges[$name] ?? [] as $sample) {
            if (($sample['labels']['engine'] ?? null) === $engine) {
                return $sample['value'];
            }
        }

        return null;
    }

    private function artifact(DateTimeImmutable $createdAt, int $sizeBytes): BackupArtifact
    {
        return new BackupArtifact(
            id: BackupId::generate(DatabaseEngine::Postgres, BackupKind::LogicalFull, $createdAt),
            engine: DatabaseEngine::Postgres,
            kind: BackupKind::LogicalFull,
            environment: 'prod',
            createdAt: $createdAt,
            sizeBytes: $sizeBytes,
            checksum: BackupChecksum::sha256(str_repeat('a', 64)),
            storeKey: 'backups/bk-1',
            codec: CompressionCodec::Zstd,
            sourceRef: SourceRef::none(),
        );
    }
}

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class FixedCatalog implements BackupCatalogReadModelInterface
{
    /** @param array<string, BackupArtifact> $latest keyed by engine value */
    public function __construct(private readonly array $latest) {}

    public function byId(string $backupId): ?BackupArtifact
    {
        return null;
    }

    public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array
    {
        return [];
    }

    public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact
    {
        return $this->latest[$engine->value] ?? null;
    }

    /** @param non-empty-list<BackupKind> $kinds */
    public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact
    {
        $artifact = $this->latest($engine, $environment);

        return $artifact !== null && \in_array($artifact->kind, $kinds, true) ? $artifact : null;
    }
}

final class RecordingMetrics implements MetricsInterface
{
    /** @var array<string, list<array{labels: array<string, string>, value: float}>> */
    public array $gauges = [];

    public function counter(string $name, array $labels = []): CounterInterface
    {
        return new class implements CounterInterface {
            public function increment(float $by = 1.0): void {}
        };
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        return new class ($this, $name, $labels) implements GaugeInterface {
            public function __construct(
                private RecordingMetrics $sink,
                private string $name,
                private array $labels,
            ) {}

            public function set(float $value): void
            {
                $this->sink->gauges[$this->name][] = ['labels' => $this->labels, 'value' => $value];
            }

            public function increment(float $by = 1.0): void {}
            public function decrement(float $by = 1.0): void {}
        };
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        return new class implements HistogramInterface {
            public function observe(float $value): void {}
        };
    }
}
