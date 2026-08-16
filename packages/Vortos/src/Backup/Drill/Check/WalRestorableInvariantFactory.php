<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Check;

use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Pitr\PostgresWalFetcher;

/**
 * Builds {@see WalRestorableInvariant} with the environment the catalog is actually written under.
 *
 * A factory for the same reason {@see \Vortos\Backup\Observability\BackupFreshnessCollectorFactory}
 * is one, and against the same trap: backups are catalogued under
 * {@see \Vortos\Backup\Environment\DefaultEnvironment::NAME} ('production') to match the deploy
 * manifests, while APP_ENV is 'prod'. Baking the wrong literal in at compile time is how the
 * freshness gauge came to filter on a value no row has ever carried.
 *
 * It matters more here than usual. Given the wrong environment this invariant finds no segments and
 * returns pass("no archived WAL for this environment") — a green result that asserts nothing, in
 * the one check that exists to prove WAL is recoverable. That is precisely the shape of the
 * long-standing RowCountInvariant bug, and it is not a shape worth reproducing.
 */
final class WalRestorableInvariantFactory
{
    public function __construct(
        private readonly BackupConfigLoader $loader,
    ) {}

    public function create(
        PostgresWalFetcher $fetcher,
        WalVolumeReadModelInterface $catalog,
    ): WalRestorableInvariant {
        return new WalRestorableInvariant($fetcher, $catalog, $this->loader->environment());
    }
}
