<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Driver\Postgres;

use PDO;
use RuntimeException;
use Throwable;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\ContainerSpec;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Restores each drill into a **disposable PostgreSQL container** rather than a scratch database on an
 * existing server.
 *
 * WHY THIS EXISTS, given {@see EphemeralDatabaseProvisioner} already "works": that one runs
 * `CREATE DATABASE` on a server you nominate, and in practice the server people nominate is the
 * production primary. Two consequences, one obvious and one not.
 *
 * The obvious one is blast radius: a restore drill competing with live traffic for CPU, disk and
 * connections, executing DDL on the primary with production credentials, on a weekly cron.
 *
 * The subtle one is what the drill actually proves — and it is the reason this class is the default.
 * A scratch database on the production server inherits an environment that is already correct: the
 * extensions are installed, the roles exist, the locale and the server version match by definition.
 * A restore there cannot fail for any of the reasons a real disaster recovery fails. It answers "do
 * these bytes load into the machine they came from?", which is never the question. A drill is only
 * worth its cron slot if it answers "do these bytes reconstitute a working database on a *clean*
 * server?" — and that requires a clean server.
 *
 * So: a fresh container, pinned to the production engine version, on the shared network, torn down in
 * a `finally`. Data lives on tmpfs, so an escaped container leaves nothing on disk. Every container is
 * labelled and each provision sweeps the previous run's orphans first, which covers the one case
 * teardown structurally cannot — being SIGKILLed between run and remove.
 *
 * Docker is reached through the least-privilege socket-proxy, never a raw socket
 * (see {@see \Vortos\Backup\Drill\Container\DockerEngineContainerRuntime}).
 */
#[AsDriver('postgres-container')]
final class ContainerizedDatabaseProvisioner implements DrillEnvironmentProvisionerInterface
{
    /** Marks every container this provisioner creates, so orphans are always identifiable. */
    public const ORPHAN_LABEL = 'vortos.backup.drill';

    private const DB_NAME = 'drill';
    private const DB_USER = 'drill';

    public function __construct(
        private readonly ContainerRuntimeInterface $runtime,
        /**
         * Pin this to the production server version. A drill that restores into a different major
         * version is testing a migration you are not planning to perform.
         */
        private readonly string $image = 'postgres:18-alpine',
        private readonly ?string $network = null,
        private readonly int $readyTimeoutSeconds = 120,
        private readonly int $tmpfsSizeBytes = 2147483648,
    ) {
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'ephemeral_db' => true,
            'isolated_server' => true,
            'clean_room' => true,
        ]);
    }

    public function provision(DatabaseEngine $engine): DrillEnvironment
    {
        if ($engine !== DatabaseEngine::Postgres) {
            throw new RuntimeException('Postgres container provisioner cannot provision ' . $engine->value);
        }

        // Sweep before we start: if the last drill was killed mid-flight its container is still here.
        $this->runtime->removeOrphans(self::ORPHAN_LABEL);

        $this->runtime->ensureImage($this->image);

        $name = 'vortos-drill-' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));

        $handle = $this->runtime->run(new ContainerSpec(
            image: $this->image,
            name: $name,
            env: [
                'POSTGRES_DB' => self::DB_NAME,
                'POSTGRES_USER' => self::DB_USER,
                'POSTGRES_PASSWORD' => $password,
                // The drill database is destroyed minutes from now and never serves a request; skip
                // the fsync durability the restore does not need and halve the restore time.
                'PGOPTIONS' => '-c fsync=off -c full_page_writes=off -c synchronous_commit=off',
            ],
            labels: [self::ORPHAN_LABEL => '1'],
            network: $this->network,
            tmpfsPath: '/var/lib/postgresql/data',
            tmpfsSizeBytes: $this->tmpfsSizeBytes,
        ));

        $dsn = sprintf(
            'pgsql://%s:%s@%s:5432/%s',
            self::DB_USER,
            urlencode($password),
            $handle->host,
            self::DB_NAME,
        );

        try {
            $this->awaitReady($handle, $password);
        } catch (Throwable $e) {
            // Never leave a container behind because it failed to become ready.
            $this->runtime->remove($handle);

            throw $e;
        }

        // The label carries the container id so teardown can find it from the DrillEnvironment alone.
        return new DrillEnvironment($dsn, $handle->id);
    }

    public function teardown(DrillEnvironment $env): void
    {
        $this->runtime->remove(new ContainerHandle($env->label, $env->label, $env->label));
    }

    /**
     * Postgres reports "ready" once during its init sequence and then restarts, so a single successful
     * connect is not proof of readiness. Poll until the server both accepts a connection and answers a
     * query, and keep polling through the expected early failures.
     */
    private function awaitReady(ContainerHandle $handle, string $password): void
    {
        $deadline = microtime(true) + $this->readyTimeoutSeconds;
        $lastError = 'timed out before the drill database accepted connections';

        while (microtime(true) < $deadline) {
            try {
                $pdo = new PDO(
                    sprintf('pgsql:host=%s;port=5432;dbname=%s;connect_timeout=3', $handle->host, self::DB_NAME),
                    self::DB_USER,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
                );
                $pdo->query('SELECT 1');

                return;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                usleep(500_000);
            }
        }

        throw new RuntimeException(sprintf(
            'Drill container %s never became ready within %ds: %s',
            $handle->name,
            $this->readyTimeoutSeconds,
            $lastError,
        ));
    }
}
