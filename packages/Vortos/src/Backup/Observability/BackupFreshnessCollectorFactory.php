<?php

declare(strict_types=1);

namespace Vortos\Backup\Observability;

use Psr\Clock\ClockInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

/**
 * Builds the freshness collector from the backup configuration rather than from the environment.
 *
 * This exists because the two disagree, by design. Backups are catalogued under
 * {@see \Vortos\Backup\Environment\DefaultEnvironment::NAME} ('production') so that the catalog
 * lines up with the deploy and release manifests; APP_ENV is 'prod'. The collector was wired with
 * the latter, so every catalog lookup filtered on an environment no row has ever carried. It found
 * nothing, reported backup_present=0 for every engine, and — because it short-circuits when no
 * backup is found — never emitted the age or size gauges at all.
 *
 * The failure is worse than a blank dashboard. The freshness gauge is the one signal designed to
 * catch a backup that stopped running rather than started failing, and a threshold alert on an age
 * series that is never emitted cannot fire. The monitor built to detect silence was itself silent,
 * on an installation with thousands of catalogued artifacts.
 *
 * A factory rather than a constructor argument because the config is loaded at runtime — the
 * container cannot know the environment at compile time without re-reading config/backup.php
 * itself, which is exactly the duplication that caused the drift.
 */
final class BackupFreshnessCollectorFactory
{
    public function __construct(
        private readonly BackupConfigLoader $loader,
    ) {}

    public function create(
        BackupCatalogReadModelInterface $catalog,
        ClockInterface $clock,
        ?FrameworkTelemetry $telemetry = null,
    ): BackupFreshnessCollector {
        return new BackupFreshnessCollector(
            $catalog,
            $clock,
            $this->loader->engines(),
            $this->loader->environment(),
            $telemetry,
        );
    }
}
