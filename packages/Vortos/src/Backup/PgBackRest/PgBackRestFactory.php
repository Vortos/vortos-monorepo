<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\DR\PitrAnchorReaderInterface;
use Vortos\Backup\Drill\Container\ContainerExecInterface;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\DrillReportStoreInterface;
use Vortos\Backup\Drill\InvariantCheck;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Health\WalFileSizeResolverInterface;

/**
 * Builds the pgBackRest services from config/backup.php at runtime, so whether they exist follows the
 * declaration (->pgBackRest()), never the order extensions load in. Every method returns null when the
 * installation does not declare pgBackRest; consumers take nullable dependencies and say so plainly.
 */
final class PgBackRestFactory
{
    public function __construct(private readonly BackupConfigLoader $loader) {}

    public function client(ContainerExecInterface $docker): ?PgBackRestClient
    {
        $repository = $this->loader->pgBackRestOrNull();

        return $repository === null
            ? null
            : new PgBackRestClient(new DockerExecPgBackRestExecutor($docker, $repository), $repository);
    }

    /**
     * @param iterable<InvariantCheck> $invariantChecks
     */
    public function drill(
        ?PgBackRestClient $client,
        ContainerRuntimeInterface $runtime,
        PromotedServerProbeInterface $probe,
        Connection $source,
        WalFileSizeResolverInterface $segmentSize,
        DrillReportStoreInterface $reports,
        BackupEventSinkInterface $events,
        ClockInterface $clock,
        iterable $invariantChecks = [],
        ?string $network = null,
        int $timeoutSeconds = 5400,
    ): ?PgBackRestDrill {
        if ($client === null) {
            return null;
        }

        return new PgBackRestDrill(
            $client,
            $runtime,
            $probe,
            $source,
            $segmentSize,
            $reports,
            $events,
            $clock,
            $this->loader->recoveryObjectives(),
            $invariantChecks,
            $network,
            $timeoutSeconds,
        );
    }

    public function lifecycle(?PgBackRestClient $client, ?PgBackRestDrill $drill): ?PgBackRestLifecycle
    {
        return $client === null || $drill === null ? null : new PgBackRestLifecycle($client, $drill);
    }

    public function anchorReader(?PgBackRestClient $client, WalFileSizeResolverInterface $segmentSize): ?PitrAnchorReaderInterface
    {
        return $client === null ? null : new PgBackRestPitrAnchorReader($client, $segmentSize);
    }
}
