<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;

/**
 * R7-3: deploy:doctor phase analysis of pending migrations.
 *
 * Runs BEFORE a deploy is attempted so a destructive-but-un-annotated migration is caught at
 * doctor time (not only when the deploy-runtime guard trips). Fail-closed: any error resolving
 * pending migrations is itself a failure.
 *
 *  - FAIL if any pending migration contains destructive DDL with no #[DeployPhase] declaration.
 *  - PASS otherwise (pending contract migrations are reported as informational detail; the
 *    contract soak/readiness gate is enforced by SchemaCompatibilityCheck).
 */
final class PendingMigrationPhaseCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly MigrationPhaseReaderInterface $phaseReader,
    ) {}

    public function id(): string
    {
        return 'schema.pending-phase';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Schema;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        try {
            $pending = $context->desiredManifest->schemaFingerprint->missingFrom(
                $context->currentState->appliedFingerprint,
            );
        } catch (\Throwable $e) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'could not resolve pending migrations for phase analysis',
                $e->getMessage(),
                'Ensure the release manifest and applied-migration set are readable before deploying.',
            );
        }

        if ($pending === []) {
            return PreflightFinding::pass(
                $this->id(),
                $this->category(),
                'no pending migrations to phase-analyze',
            );
        }

        $destructiveUnannotated = [];
        $contract = [];

        foreach ($pending as $id) {
            if ($this->phaseReader->isDestructiveAndUnannotated($id)) {
                $destructiveUnannotated[] = $id;
                continue;
            }

            if ($this->phaseReader->phaseOf($id) === MigrationPhase::Contract) {
                $contract[] = $id;
            }
        }

        if ($destructiveUnannotated !== []) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'pending migration(s) contain destructive DDL without a #[DeployPhase] declaration',
                sprintf('destructive & un-annotated: [%s]', implode(', ', $destructiveUnannotated)),
                'Annotate them #[DeployPhase(MigrationPhase::Contract)] (ship behind soak/flag gate), or '
                . '#[DeployPhase(MigrationPhase::Expand)] with #[AllowFullTableRewrite] if the rewrite is intentional.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'pending migrations are phase-safe (no un-annotated destructive DDL)',
            $contract === [] ? 'no pending contract migrations' : sprintf('pending contract (soak-gated): [%s]', implode(', ', $contract)),
        );
    }
}
