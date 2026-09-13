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
    /** @var \Closure(string): array{ok: bool, error: string|null, client_missing?: bool} */
    private \Closure $probe;

    /**
     * @param (\Closure(string): array{ok: bool, error: string|null, client_missing?: bool})|null $probe
     *        injectable so the check is testable without a live cluster
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

        // "I could not ask" is not "the answer is no", and conflating them is worse than either.
        // This check shells out to a PostgreSQL client, and the lean deploy image deliberately
        // omits one (the toolchain lives on the backup role — see backupToolchainExternal). Reported
        // as a refused replication connection, a missing binary sends the operator to pg_hba.conf
        // and a REPLICATION role attribute to fix a cluster that was configured correctly all along.
        if (($result['client_missing'] ?? false) === true) {
            return ReplicationAccessFinding::failed(
                'Replication access could not be verified: no PostgreSQL client is available on this '
                . 'node, so nothing here can open a replication connection. This says nothing about '
                . 'whether the database would accept one.',
                <<<'FIX'
                Verify replication access where the backup toolchain actually lives — run
                `backup:doctor` on the backup role/worker image, which carries the client.

                If this is a lean deploy image that intentionally omits the client, declare it:

                    // config/deploy.php
                    ->backupToolchainExternal(true)

                That is the same declaration the backup.toolchain gate already honours, and it tells
                this gate to defer rather than guess.
                FIX,
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

    /**
     * Turns the application's DSN into a replication DSN libpq will accept.
     *
     * The application DSN is written for Doctrine, whose PostgreSQL scheme is `pgsql://`. libpq
     * knows only `postgresql://` and `postgres://`, and it reads anything else as a single
     * unknown option name — so an untranslated DSN fails with "invalid connection option" on a
     * cluster that would have accepted the connection, and this check sends the operator to
     * pg_hba.conf to fix what was never broken.
     */
    private function asReplicationDsn(string $dsn): string
    {
        $dsn = (string) preg_replace('#^(?:pgsql|pdo-pgsql|pdo_pgsql)://#i', 'postgresql://', $dsn);

        if (str_contains($dsn, 'replication=')) {
            return $dsn;
        }

        return $dsn . (str_contains($dsn, '?') ? '&' : '?') . 'replication=database';
    }

    /**
     * Splits the password out of a libpq URL so it can travel in PGPASSWORD instead of argv.
     *
     * A password on the command line is readable by every process on the node through
     * /proc/<pid>/cmdline for as long as psql runs, and psql repeats an unparseable URL verbatim in
     * its error — which this check then prints as its finding. Neither is acceptable for a
     * credential, so the URL handed to psql never carries one.
     *
     * @internal public for testing only
     *
     * @return array{dsn: string, password: string|null}
     */
    public static function withoutPassword(string $dsn): array
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*://)([^:@/?\#]*):([^@/?\#]*)@(.*)$#is', $dsn, $m) !== 1) {
            return ['dsn' => $dsn, 'password' => null];
        }

        return ['dsn' => $m[1] . $m[2] . '@' . $m[4], 'password' => rawurldecode($m[3])];
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
            // Probe for the client before probing with it, so its absence is reported as itself.
            $located = @shell_exec('command -v psql 2>/dev/null');
            if ($located === null || trim((string) $located) === '') {
                return [
                    'ok' => false,
                    'error' => 'psql is not installed on this node',
                    'client_missing' => true,
                ];
            }

            ['dsn' => $url, 'password' => $password] = self::withoutPassword($dsn);

            $env = getenv();
            if ($password !== null) {
                $env['PGPASSWORD'] = $password;
            }

            $process = proc_open(
                ['psql', $url, '-Atc', 'IDENTIFY_SYSTEM'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );

            if (!\is_resource($process)) {
                return ['ok' => false, 'error' => 'psql could not be started'];
            }

            // IDENTIFY_SYSTEM prints one row and an error is one line, so neither pipe can fill
            // while the other is read.
            $output = stream_get_contents($pipes[1]) . ' ' . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode === 0) {
                return ['ok' => true, 'error' => null];
            }

            $error = trim(preg_replace('/\s+/', ' ', $output) ?? '');
            if ($password !== null && $password !== '') {
                $error = str_replace([$password, rawurlencode($password)], '***', $error);
            }

            return ['ok' => false, 'error' => $error !== '' ? $error : 'psql exited ' . $exitCode];
        };
    }
}
