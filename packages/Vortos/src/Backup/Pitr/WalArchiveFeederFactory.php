<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;

/**
 * Builds {@see WalArchiveFeeder} with the environment the WAL catalog is actually written under.
 *
 * The same factory-not-constant discipline as {@see \Vortos\Backup\Drill\Check\WalRestorableInvariantFactory},
 * and for the same reason: backups are catalogued under
 * {@see \Vortos\Backup\Environment\DefaultEnvironment::NAME} ('production') to match the deploy
 * manifests, while APP_ENV is 'prod'. Resolving that at compile time is how the freshness gauge came
 * to filter on a value no row has ever carried.
 *
 * Here the consequence is worse than a blank dashboard. Given the wrong environment, every segment
 * the recovering cluster asks for is looked up under a prefix nothing was ever written to, so every
 * request is answered "not in the archive" — which PostgreSQL reads as the end of the log. Recovery
 * would stop at the base backup's own instant, promote cleanly, and the drill would report a
 * successful point-in-time recovery having replayed nothing at all.
 */
final class WalArchiveFeederFactory
{
    public function __construct(
        private readonly BackupConfigLoader $loader,
    ) {}

    public function create(
        ContainerRuntimeInterface $runtime,
        PostgresWalFetcher $fetcher,
        int $maxSegments,
        int $timeoutSeconds,
    ): WalArchiveFeeder {
        return new WalArchiveFeeder(
            $runtime,
            $fetcher,
            $this->loader->environment(),
            $maxSegments,
            $timeoutSeconds,
        );
    }
}
