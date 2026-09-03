<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Backup\Doctor\ReplicationAccessInspector;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Gates a deploy on the database accepting the REPLICATION connection physical base backups need.
 *
 * WHY THIS IS A SEPARATE GATE FROM {@see BackupToolchainCheck}
 * -----------------------------------------------------------
 * That check asks whether pg_basebackup is INSTALLED. This one asks whether it can actually CONNECT.
 * The two failed independently in production: the binary was present, the right version, on PATH —
 * every toolchain signal green — while PostgreSQL refused the connection outright, because
 * replication is authorised through a pg_hba path that the "all" keyword does not cover, and needs
 * a role attribute ordinary application roles lack.
 *
 * The result was physical base backups that had never once succeeded, and therefore archived WAL
 * with nothing to replay onto: point-in-time recovery that reported healthy and could restore
 * nothing.
 *
 * It gates a DEPLOY rather than merely warning because the prerequisite lives in cluster
 * configuration, not application code — pg_hba.conf cannot be set from SQL, so a rebuilt volume or
 * a migrated database can silently lose it, and the next sign would be a failed recovery. Blocking
 * is the honest response to "your backups are not restorable".
 *
 * Only applies when physical base backups are declared; a logical-dump-only setup never needs
 * replication access and must not be gated on it.
 *
 * AND ONLY WHEN THIS NODE CAN ACTUALLY ASK. The probe opens a replication connection with a
 * PostgreSQL client, which the lean deploy image deliberately does not carry — the whole point of
 * {@see \Vortos\Deploy\Definition\DeploymentDefinitionBuilder::backupToolchainExternal()}. Run
 * there without deferring, this gate blocks every deploy with "the database refuses a replication
 * connection" on a cluster that is configured perfectly, because the only thing it really
 * discovered is that it has no client. So it honours the same declaration the toolchain gate does,
 * and points at the node that can answer the question.
 */
final class BackupReplicationAccessCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly ReplicationAccessInspector $inspector,
        private readonly BackupScheduleRegistry $schedules,
        private readonly ?string $configuredEngine,
        private readonly string $dsn,
        /** Env-derived default; config/deploy.php wins when it says anything at all. */
        private readonly bool $toolchainExternal = false,
    ) {
    }

    public function id(): string
    {
        return 'backup.replication_access';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        if ($this->configuredEngine === null || $this->dsn === '') {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'No backup engine or DSN configured — replication access check not applicable.',
            );
        }

        $engine = DatabaseEngine::tryFrom($this->configuredEngine);

        if ($engine === null) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                sprintf('Unknown backup engine "%s" — replication access check skipped.', $this->configuredEngine),
            );
        }

        // Resolved exactly as BackupToolchainCheck resolves it, so one declaration governs both
        // gates and they cannot disagree about whether this image carries a database client.
        $external = $context->definition->backupToolchainExternal ?? $this->toolchainExternal;
        if ($external) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'Backup toolchain is external — replication access is verified on the backup role, not here.',
                'The lean deploy image intentionally omits the database client, so no replication '
                . 'connection can be opened from it. Verify with backup:doctor on the backup role/worker '
                . 'image, which carries the toolchain.',
            );
        }

        $kinds = [];
        foreach ($this->schedules as $schedule) {
            $kinds[] = $schedule->kind;
        }

        $finding = $this->inspector->inspect($engine, $this->dsn, array_values(array_unique($kinds, SORT_REGULAR)));

        if (!$finding->applicable) {
            return PreflightFinding::pass($this->id(), $this->category(), $finding->message);
        }

        if (!$finding->isFailure()) {
            return PreflightFinding::pass($this->id(), $this->category(), $finding->message);
        }

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            'Physical base backups cannot run — the database refuses a replication connection.',
            $finding->message,
            $finding->remediation,
        );
    }
}
