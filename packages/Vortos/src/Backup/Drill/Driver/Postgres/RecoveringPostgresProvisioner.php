<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Driver\Postgres;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\Container\ContainerHandle;
use Vortos\Backup\Drill\Container\ContainerRuntimeInterface;
use Vortos\Backup\Drill\Container\ContainerSpec;
use Vortos\Backup\Drill\DrillEnvironment;
use Vortos\Backup\Drill\DrillEnvironmentProvisionerInterface;
use Vortos\Backup\Restore\Driver\Postgres\PostgresPitrRestoreTarget;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Stands up an empty, UNSTARTED PostgreSQL container for a point-in-time recovery to be laid into.
 *
 * WHY NOT {@see ContainerizedDatabaseProvisioner}, which already makes disposable Postgres
 * containers. That one creates *and starts* a server, waits for it to accept connections, and hands
 * back a live empty database — exactly right for a logical restore, and unusable for a physical
 * one. A base backup has to be in place BEFORE the postmaster boots, because `recovery.signal` and
 * `postgresql.auto.conf` are read once at startup; a cluster that receives them afterwards has
 * already started up normally at the instant the base was taken, replaying nothing, and every
 * invariant downstream would pass. That is the silent-green failure this whole feature exists to
 * remove, so the two lifecycles are kept as two classes rather than one with a mode flag.
 *
 * THREE DIFFERENCES FROM THE LOGICAL PROVISIONER, each forced:
 *
 *  - **Created, not started.** {@see PostgresPitrRestoreTarget} owns the start, after it has written
 *    the data directory and the recovery configuration.
 *  - **Disk, not tmpfs.** The logical drill puts its data directory in RAM because a 12 MB dump
 *    fits. A base backup plus a replayed WAL chain does not, and — more subtly — a tmpfs mount does
 *    not exist until the container runs, so bytes written into that path beforehand would be
 *    shadowed the moment it started, producing an empty data directory and a baffling error.
 *  - **The source cluster's credentials.** A physical restore is a byte copy: it carries production's
 *    roles, passwords and `pg_hba.conf` with it. There is no `POSTGRES_USER` to invent, so the
 *    verification DSN is built from the connection the backup was taken through.
 *
 * Containers are labelled, swept on the way in, and removed in the drill's `finally` — the same
 * disposal discipline as the logical provisioner, and it matters more here because this one holds a
 * full copy of production. It is never given a published port: it is reachable only by container
 * name on the drill network, the way the rest of the stack talks to itself.
 */
#[AsDriver('postgres-pitr-container')]
final class RecoveringPostgresProvisioner implements DrillEnvironmentProvisionerInterface
{
    /**
     * Deliberately the SAME label the logical provisioner sweeps.
     *
     * Both kinds of drill container are equally orphanable and equally disposable, and a second
     * label would mean each provisioner cleans up only after itself — so a PITR drill killed
     * mid-flight would leave a container holding a full copy of production until the next PITR
     * drill a week later, rather than until the next drill of any kind tomorrow.
     */
    public const ORPHAN_LABEL = ContainerizedDatabaseProvisioner::ORPHAN_LABEL;

    public function __construct(
        private readonly ContainerRuntimeInterface $runtime,
        /** The connection the backups are taken through — the source of the restored cluster's identity. */
        private readonly Connection $source,
        /**
         * Pin to the production server version. A base backup is a physical artifact: restoring it
         * under a different MAJOR version is not a drill, it is an unplanned upgrade that will
         * refuse to start.
         */
        private readonly string $image = 'postgres:18-alpine',
        private readonly ?string $network = null,
        /**
         * Where the image expects its cluster. PostgreSQL 18's official image places it at
         * `/var/lib/postgresql/18/docker`; the value must match the image's own `PGDATA`, since that
         * is the path its entrypoint will start.
         */
        private readonly string $pgdata = '/var/lib/postgresql/18/docker',
    ) {
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            'ephemeral_db' => true,
            'isolated_server' => true,
            'clean_room' => true,
            'point_in_time' => true,
        ]);
    }

    public function provision(DatabaseEngine $engine): DrillEnvironment
    {
        if ($engine !== DatabaseEngine::Postgres) {
            throw new RuntimeException('The PITR provisioner cannot provision ' . $engine->value);
        }

        $this->runtime->removeOrphans(self::ORPHAN_LABEL);
        $this->runtime->ensureImage($this->image);

        $name = 'vortos-pitr-' . bin2hex(random_bytes(6));

        $handle = $this->runtime->create(new ContainerSpec(
            image: $this->image,
            name: $name,
            labels: [self::ORPHAN_LABEL => '1'],
            network: $this->network,
            command: [$this->bootScript()],
            entrypoint: ['/bin/sh', '-c'],
        ));

        $params = $this->sourceParams();

        return new DrillEnvironment(
            dsn: sprintf(
                'pgsql://%s:%s@%s:5432/%s',
                rawurlencode($params['user']),
                rawurlencode($params['password']),
                $handle->host,
                $params['dbname'],
            ),
            label: $handle->id,
            options: [
                PostgresPitrRestoreTarget::OPTION_CONTAINER_ID => $handle->id,
                PostgresPitrRestoreTarget::OPTION_CONTAINER_NAME => $handle->name,
                PostgresPitrRestoreTarget::OPTION_PGDATA => $this->pgdata,
            ],
        );
    }

    public function teardown(DrillEnvironment $env): void
    {
        $this->runtime->remove(new ContainerHandle($env->label, $env->label, $env->label));
    }

    /**
     * Fix up the data directory, then hand over to the image's own entrypoint.
     *
     * Both lines are load-bearing and were established empirically against the production images:
     *
     *  - `chown`: Docker preserves the uid recorded in an uploaded tar, and a base backup taken from
     *    an Alpine PostgreSQL image carries uid 70. That is already correct — but the `/vortos`
     *    control directory and any parent the extractor had to create are not, and PostgreSQL
     *    refuses to start over a directory tree it cannot own.
     *  - `chmod 0700`: the official image creates `$PGDATA` mode 1777 so its own initdb can populate
     *    it. PostgreSQL rejects a data directory with group or world permissions outright, so a
     *    cluster restored into that directory would fail at startup complaining about permissions —
     *    an error that reads like a broken backup and is not one.
     *
     * `exec` so the postmaster is PID 1 and receives the stop signal directly; the drill's teardown
     * force-removes the container regardless, but a container that stops cleanly does not leave the
     * daemon waiting out a kill timeout on every drill.
     */
    private function bootScript(): string
    {
        return 'set -e; '
            . 'chown -R 70:70 /var/lib/postgresql /vortos; '
            . 'chmod 0700 "$PGDATA"; '
            . 'exec /usr/local/bin/docker-entrypoint.sh postgres';
    }

    /**
     * @return array{user: string, password: string, dbname: string}
     */
    private function sourceParams(): array
    {
        $p = $this->source->getParams();

        $user = (string) ($p['user'] ?? 'postgres');
        $password = (string) ($p['password'] ?? '');
        $dbname = (string) ($p['dbname'] ?? ($p['path'] ?? 'postgres'));

        if ($dbname === '') {
            throw new RuntimeException(
                'Cannot describe the recovered cluster: the source connection names no database. A '
                . 'physical restore reproduces the source cluster exactly, so its database name is '
                . 'the only one that will exist.',
            );
        }

        return ['user' => $user, 'password' => $password, 'dbname' => $dbname];
    }
}
