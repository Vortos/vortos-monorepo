<?php

declare(strict_types=1);

namespace Vortos\Deploy\Preflight\Check;

use Vortos\Deploy\Plan\PhaseGate;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightCheckInterface;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\Schema\FingerprintRelation;

/**
 * Fail-closed schema gate (§12.1): the deploy must be schema-safe before any color
 * comes up.
 *
 *  - An *uncleared pending contract* migration blocks the deploy (a contract may not
 *    ship in the same deploy that brings up a new color).
 *  - The *rollback invariant must stay evaluable*: if the live applied set contains a
 *    migration unknown to every recorded manifest (a manual hotfix), a later rollback
 *    cannot be reasoned about — refuse now rather than discover it mid-incident.
 *  - The *fingerprint delta must be sane*: a desired schema completely disjoint from
 *    what is applied signals a wrong build / wrong environment.
 */
final class SchemaCompatibilityCheck implements PreflightCheckInterface
{
    public function __construct(
        private readonly PhaseGate $phaseGate,
        private readonly ?ManifestReadModelInterface $manifestReadModel = null,
    ) {}

    public function id(): string
    {
        return 'schema.compatible';
    }

    public function category(): PreflightCategory
    {
        return PreflightCategory::Schema;
    }

    public function check(PreflightContext $context): PreflightFinding
    {
        $state = $context->currentState;

        if ($state->pendingContract()) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'an uncleared contract migration is pending',
                sprintf('pending contract migrations: [%s]', implode(', ', $state->pendingContractMigrations)),
                'Clear the contract gate (soak window / flag signal) before deploying, or split the contract into a later deploy.',
            );
        }

        // Defensive: assert via the gate too, so the same refusal mechanism is exercised.
        $this->phaseGate->assertNoPendingContract($state);

        $applied = $state->appliedFingerprint;
        $desired = $context->desiredManifest->schemaFingerprint;

        if ($this->manifestReadModel !== null) {
            $known = $this->manifestReadModel->knownMigrationSetForEnvironment($context->environment->value);
            $unknowns = $known->unknownsIn($applied);

            if ($unknowns !== []) {
                return PreflightFinding::fail(
                    $this->id(),
                    $this->category(),
                    'applied schema contains migrations unknown to any recorded manifest',
                    sprintf('unknown applied migrations: [%s]', implode(', ', $unknowns)),
                    'Record a manifest covering the manual change, or reconcile the hotfix, so rollback stays evaluable.',
                );
            }
        }

        $relation = $desired->relationTo($applied);
        if ($relation === FingerprintRelation::Disjoint && !$applied->isEmpty() && !$desired->isEmpty()) {
            return PreflightFinding::fail(
                $this->id(),
                $this->category(),
                'desired schema is disjoint from the applied schema',
                sprintf('desired=%s applied=%s', $desired->hash, $applied->hash),
                'Verify you are deploying the correct build to the correct environment.',
            );
        }

        return PreflightFinding::pass(
            $this->id(),
            $this->category(),
            'schema is compatible: no uncleared contract, rollback evaluable, delta sane',
            sprintf('relation desired→applied: %s', $relation->value),
        );
    }
}
