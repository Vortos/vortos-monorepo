<?php

declare(strict_types=1);

namespace Vortos\Backup\Doctor;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * Verifies the database will accept the REPLICATION connection that physical base backups need.
 *
 * WHY THIS EXISTS AS A CHECK RATHER THAN A README LINE
 * ----------------------------------------------------
 * `pg_basebackup` does not open an ordinary connection — it opens a replication connection, which
 * PostgreSQL authorises through a completely separate `pg_hba.conf` path. `all` in that file's
 * DATABASE column does NOT match replication, so a cluster that every application query reaches
 * happily will still refuse pg_basebackup with:
 *
 *     FATAL: no pg_hba entry for replication connection from host "…", user "…"
 *
 * The role additionally needs the REPLICATION attribute, which ordinary application roles lack.
 *
 * Neither prerequisite is visible from anything the existing toolchain check inspects: pg_basebackup
 * was present on PATH, the right version, and the store was reachable — every signal green — while
 * physical base backups had never once succeeded. And because a base backup is what archived WAL
 * replays onto, that silently reduced point-in-time recovery to a pile of unrestorable segments.
 *
 * This check turns that from something discovered during a restore into something the deploy
 * preflight refuses. It is only meaningful when physical base backups are actually declared; a
 * logical-dump-only setup neither needs nor should be gated on replication access.
 */
final class ReplicationAccessInspector
{
    /** @var \Closure(string): array{ok: bool, error: string|null} */
    private \Closure $probe;

    /**
     * @param (\Closure(string): array{ok: bool, error: string|null})|null $probe injectable so the
     *        check is testable without a live cluster
     */
    public function __construct(?\Closure $probe = null)
    {
        $this->probe = $probe ?? $this->defaultProbe();
    }

    /**
     * @param list<BackupKind> $declaredKinds the kinds this environment is configured to produce
     */
    public function inspect(DatabaseEngine $engine, string $dsn, array $declaredKinds): ReplicationAccessFinding
    {
        if ($engine !== DatabaseEngine::Postgres) {
            return ReplicationAccessFinding::notApplicable(
                'Replication access is a PostgreSQL concern; nothing to check for this engine.',
            );
        }

        if (!\in_array(BackupKind::PhysicalBase, $declaredKinds, true)) {
            return ReplicationAccessFinding::notApplicable(
                'No physical_base backups declared — replication access is not required.',
            );
        }

        // The replication parameter is applied HERE, not inside the probe, so the guarantee is
        // structural: every probe implementation — including a test double or a future non-psql
        // one — is handed a replication DSN and cannot accidentally verify an ordinary connection,
        // which a different pg_hba rule would happily authorise while pg_basebackup still failed.
        $result = ($this->probe)($this->asReplicationDsn($dsn));

        if ($result['ok']) {
            return ReplicationAccessFinding::satisfied(
                'Replication connection accepted — pg_basebackup can run.',
            );
        }

        return ReplicationAccessFinding::failed(sprintf(
            'The database refused a replication connection, so physical_base backups cannot run '
            . 'and any archived WAL would be unrestorable. Underlying error: %s',
            $result['error'] ?? 'unknown',
        ), <<<'FIX'
        PostgreSQL authorises replication through a separate pg_hba.conf path — `all` in the
        DATABASE column does not cover it. Both of these are required:

          1. A replication rule for the backup role, e.g.
                 host replication <role> <cidr> scram-sha-256
             appended to pg_hba.conf, then `SELECT pg_reload_conf();`

          2. The role itself:
                 ALTER ROLE <role> WITH REPLICATION;

        Put both in the cluster's provisioning so a rebuilt volume does not silently lose them.
        FIX);
    }

    /** Adds the replication parameter that turns an ordinary DSN into a replication DSN. */
    private function asReplicationDsn(string $dsn): string
    {
        if (str_contains($dsn, 'replication=')) {
            return $dsn;
        }

        return $dsn . (str_contains($dsn, '?') ? '&' : '?') . 'replication=database';
    }

    /**
     * Asks the server to identify itself over the replication DSN it was given. IDENTIFY_SYSTEM is
     * the cheapest command that is ONLY valid on a replication connection, so a success here proves
     * exactly what pg_basebackup needs and nothing weaker.
     *
     * @return \Closure(string): array{ok: bool, error: string|null}
     */
    private function defaultProbe(): \Closure
    {
        return static function (string $dsn): array {
            $command = sprintf(
                'psql %s -Atc %s 2>&1',
                escapeshellarg($dsn),
                escapeshellarg('IDENTIFY_SYSTEM'),
            );

            exec($command, $output, $exitCode);

            return $exitCode === 0
                ? ['ok' => true, 'error' => null]
                : ['ok' => false, 'error' => trim(implode(' ', $output)) ?: 'psql exited ' . $exitCode];
        };
    }
}
