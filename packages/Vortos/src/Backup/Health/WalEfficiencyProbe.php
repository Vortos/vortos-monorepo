<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Watches what WAL archiving COSTS, which is the one thing nothing else here was watching.
 *
 * THE INCIDENT THIS ENCODES. For three weeks a production system shipped 22.7 GB of WAL per day to
 * object storage while generating 262 MB/day of actual WAL records — an ~87x amplification, 443 GB
 * of stored segments carrying about 5 GB of change. Every existing signal was green throughout:
 * `pg_stat_archiver.failed_count` was 0, {@see BackupFreshnessProbe} was fresh, retention ran, the
 * weekly restore drill passed. Nothing had failed. Segments were simply being shipped at their full
 * 16 MiB because `archive_timeout` forces a switch on a clock rather than when a segment fills, and
 * a forced-switch segment is padded with zeros to full size. The only mechanism that would ever have
 * reported it was a Cloudflare invoice.
 *
 * So this probe asserts proportion, not success — and on two axes, because they fail independently:
 *
 *  - **Ratio** catches compression silently ceasing to apply: a reverted codec, a config that stops
 *    reaching the archiver, a level of zero. Mean stored size jumps back to ~16 MiB per segment.
 *  - **Volume** catches the opposite problem, a genuine write explosion. Compression cannot fix that
 *    and a ratio check would call it perfectly healthy while the bill doubled.
 *
 * MONITORING kind, for the same non-negotiable reason as {@see BackupFreshnessProbe}: an
 * uneconomical archive is "page someone", never "stop serving traffic". A readiness-kind probe here
 * would drop the color out of the edge's upstream pool over a storage-efficiency concern, turning a
 * cost problem into a customer-facing outage.
 */
#[AsDriver('wal-efficiency')]
final class WalEfficiencyProbe implements HealthProbeInterface
{
    public function __construct(
        private readonly WalVolumeReadModelInterface $catalog,
        private readonly ClockInterface $clock,
        private readonly string $environment,
        /** Below this, segments are effectively shipping at full size and compression is not reaching the store. */
        private readonly float $minCompressionRatio = 4.0,
        /** The daily at-rest budget for WAL. Crossing it is a real change in write volume, not a codec regression. */
        private readonly int $maxDailyBytes = 5 * 1024 * 1024 * 1024,
        private readonly int $windowHours = 24,
    ) {}

    public function name(): string
    {
        return 'wal-efficiency';
    }

    public function kind(): ProbeKind
    {
        return ProbeKind::Monitoring;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(['off_gate' => true, 'catalog_derived' => true]);
    }

    public function check(): ProbeResult
    {
        $start = microtime(true);

        try {
            $since = $this->clock->now()->modify("-{$this->windowHours} hours");
            $volume = $this->catalog->walVolumeSince(DatabaseEngine::Postgres, $this->environment, $since);
        } catch (Throwable $e) {
            // An unreachable catalog is not evidence of an inefficient archive. Distinct condition.
            return ProbeResult::warn(
                $this->name(),
                $this->kind(),
                $this->elapsedMs($start),
                'wal_efficiency_indeterminate',
                ['error' => $e->getMessage()],
            );
        }

        $efficiency = new WalEfficiency(
            $this->environment,
            $volume['segments'],
            $volume['bytes'],
            $this->windowHours,
        );

        $context = [
            'environment'        => $this->environment,
            'window_hours'       => $this->windowHours,
            'segments'           => $efficiency->segments,
            'stored_bytes'       => $efficiency->totalStoredBytes,
            'mean_segment_bytes' => (int) round($efficiency->meanStoredBytes()),
            'compression_ratio'  => round($efficiency->compressionRatio(), 1),
            'projected_daily_gb' => round($efficiency->projectedDailyBytes() / (1024 ** 3), 2),
        ];

        // Too few segments to judge. Not a pass — a quiet window proves nothing — but emphatically
        // not an alarm either, because a probe that cries wolf on idle hours stops being read.
        if ($efficiency->indeterminate()) {
            return ProbeResult::warn(
                $this->name(),
                $this->kind(),
                $this->elapsedMs($start),
                'wal_efficiency_indeterminate',
                $context + ['reason' => 'too few segments in window to judge'],
            );
        }

        if ($efficiency->compressionRatio() < $this->minCompressionRatio) {
            return ProbeResult::fail(
                $this->name(),
                $this->kind(),
                $this->elapsedMs($start),
                'wal_compression_ineffective',
                $context + [
                    'expected_min_ratio' => $this->minCompressionRatio,
                    'hint'               => 'WAL segments are reaching the store at close to their full 16 MiB. '
                        . 'Check the configured codec (config/backup.php walCompression, or VORTOS_BACKUP_WAL_CODEC).',
                ],
            );
        }

        if ($efficiency->projectedDailyBytes() > $this->maxDailyBytes) {
            return ProbeResult::fail(
                $this->name(),
                $this->kind(),
                $this->elapsedMs($start),
                'wal_volume_over_budget',
                $context + [
                    'budget_daily_gb' => round($this->maxDailyBytes / (1024 ** 3), 2),
                    'hint'            => 'Compression is working but the volume of real WAL has grown. '
                        . 'This is a write-rate change, not a codec problem — look at what is writing.',
                ],
            );
        }

        return ProbeResult::pass($this->name(), $this->kind(), $this->elapsedMs($start), $context);
    }

    private function elapsedMs(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }
}
