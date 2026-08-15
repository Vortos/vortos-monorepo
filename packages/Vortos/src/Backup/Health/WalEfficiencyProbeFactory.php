<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Config\BackupConfigLoader;

/**
 * Builds {@see WalEfficiencyProbe} with the environment the catalog is actually written under.
 *
 * Same reasoning as {@see \Vortos\Backup\Observability\BackupFreshnessCollectorFactory}, and the
 * same trap it was written to close: backups are catalogued under
 * {@see \Vortos\Backup\Environment\DefaultEnvironment::NAME} ('production') to match the deploy
 * manifests, while APP_ENV is 'prod'. Wiring the literal environment at compile time is how the
 * freshness gauge came to filter on a value no row has ever carried — it matched nothing, reported
 * healthy, and the one monitor built to detect silence was itself silent.
 *
 * A probe that silently measures an empty set is strictly worse than no probe here, because this
 * one exists to catch a fault that produces no other symptom.
 */
final class WalEfficiencyProbeFactory
{
    public function __construct(
        private readonly BackupConfigLoader $loader,
    ) {}

    public function create(
        WalVolumeReadModelInterface $catalog,
        ClockInterface $clock,
        float $minCompressionRatio,
        int $maxDailyBytes,
    ): WalEfficiencyProbe {
        return new WalEfficiencyProbe(
            $catalog,
            $clock,
            $this->loader->environment(),
            $minCompressionRatio,
            $maxDailyBytes,
        );
    }
}
