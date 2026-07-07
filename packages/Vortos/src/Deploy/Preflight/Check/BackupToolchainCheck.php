<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Doctor\BackupToolchainInspector;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;

/**
 * Deploy-time bridge for the backup toolchain (STAGE-F-1). It reuses the exact same
 * {@see BackupToolchainInspector} as the backup:doctor command, so the gate a human runs and
 * the gate the deploy enforces agree byte-for-byte.
 *
 * Scope (never a false Fail) — the three states an operator can be in:
 *   - no backup engine configured → **Skip**: deploy does not force backups on apps that don't use
 *     vortos-backup.
 *   - engine configured, toolchain lives on the deploy image → **Fail** when its client binaries are
 *     missing / too old (fail-closed: a broken backup toolchain is caught before cutover, not at the
 *     first real backup).
 *   - engine configured, toolchain is EXTERNAL (R8-2) → **Pass (informational)**: the backup runs on
 *     a dedicated backup role/worker image that carries the DB client, not the lean deploy image, so
 *     the deploy image is the wrong place to assert the toolchain. The assertion still happens — on
 *     the backup role's own image, via backup:doctor.
 *
 * This class is only loaded when vortos-backup is installed; its registration in DeployExtension is
 * guarded by class_exists, exactly like {@see MigrationDriftCheck}.
 */
final class BackupToolchainCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly BackupToolchainInspector $inspector,
        private readonly ?string $configuredEngine,
        private readonly bool $toolchainExternal = false,
    ) {
    }

    public function id(): string
    {
        return 'backup.toolchain';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Capability;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $configured = $this->configuredEngine !== null ? trim($this->configuredEngine) : '';

        if ($configured === '') {
            return PreflightFinding::skip(
                $this->id(),
                $this->category(),
                'no backup engine configured (VORTOS_BACKUP_ENGINE unset); deploy does not require backups. '
                . '(Set VORTOS_BACKUP_ENGINE to demand the toolchain in the deploy image, or additionally '
                . 'VORTOS_BACKUP_TOOLCHAIN_EXTERNAL=true if it lives on a dedicated backup role.)',
            );
        }

        $engine = DatabaseEngine::tryFrom($configured);
        if ($engine === null) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                sprintf('VORTOS_BACKUP_ENGINE="%s" names no known backup engine', $configured),
                sprintf('Known engines: %s.', implode(', ', DatabaseEngine::all())),
                'Set VORTOS_BACKUP_ENGINE to one of the known engines, or unset it if this app takes no backups.',
            );
        }

        // R8-2: engine configured but toolchain runs on a dedicated backup role/worker image (the
        // STAGE-F-1 lean-color pattern). The deploy image is deliberately lean; asserting the client
        // here would contradict that. Pass informationally — the toolchain is verified on the backup
        // role's own image via backup:doctor. We still validated the engine name above (fail-closed).
        // config/deploy.php (carried on the definition) wins when set; otherwise the env-derived
        // default applies.
        $external = $context->definition->backupToolchainExternal ?? $this->toolchainExternal;
        if ($external) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                sprintf('%s backup toolchain is external (runs on the backup role, not the deploy image)', $engine->value),
                'VORTOS_BACKUP_TOOLCHAIN_EXTERNAL=true — the lean deploy image intentionally omits the DB '
                . 'client; the backup role/worker image carries it. Verify it there with backup:doctor.',
            );
        }

        $report = $this->inspector->inspect($engine);
        if ($report->isSatisfied()) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                sprintf('%s backup toolchain present', $engine->value),
            );
        }

        $failing = array_map(
            static fn ($f): string => $f->message,
            $report->failures(),
        );

        return PreflightFinding::fail(
            $this->id(),
            $this->category(),
            sprintf('%s backup toolchain is incomplete in the deploy image', $engine->value),
            implode(' ', $failing),
            sprintf(
                'Install the %s client tools (matching the server major) in the image that runs backups, '
                . 'then re-run. Verify with the backup:doctor command.',
                $engine->value,
            ),
        );
    }
}
