<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

use Vortos\Deploy\Audit\ActorIdentitySource;
use Vortos\Deploy\Audit\DeployAuditRecorder;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Exception\RollbackTargetNotFoundException;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Release\ReadModel\ManifestReadModelInterface;

/**
 * 'deploy:rollback' orchestration — and the single auto-rollback code path reused by
 * {@see DeployRunner}.
 *
 * Resolves the rollback target (explicit '--to' build or previous known-good),
 * enforces the Block 8 rollback invariant via {@see RollbackGuard} (which throws
 * {@see \Vortos\Deploy\Exception\RollbackRefusedException} on an illegal target), then
 * plans and executes the rollback through the target driver. Refusal is first-class:
 * unsafe targets never execute.
 */
final class RollbackRunner
{
    public function __construct(
        private readonly LayeredDefinitionResolver $resolver,
        private readonly ManifestReadModelInterface $manifestReadModel,
        private readonly RollbackGuard $rollbackGuard,
        private readonly DeployTargetRegistry $targets,
        private readonly DeployPreflightStateBuilder $stateBuilder,
        private readonly PlanRenderer $planRenderer,
        private readonly ?DeployAuditRecorder $auditRecorder = null,
    ) {}

    /**
     * @throws \Vortos\Deploy\Exception\RollbackRefusedException on an illegal target
     * @throws RollbackTargetNotFoundException when no target can be resolved
     */
    public function rollback(
        string $env,
        ?string $targetBuildId = null,
        string $actorId = 'unknown',
        ActorIdentitySource $actorIdentitySource = ActorIdentitySource::Local,
    ): DeployOutcome {
        $definition = $this->resolver->resolve($env);

        if ($targetBuildId !== null) {
            $target = $this->manifestReadModel->manifest($targetBuildId);
            if ($target === null) {
                throw RollbackTargetNotFoundException::unknownBuild($targetBuildId, $env);
            }
        } else {
            $target = $this->manifestReadModel->previousForEnvironment($env);
            if ($target === null) {
                throw RollbackTargetNotFoundException::noPrevious($env);
            }
        }

        // Fail-closed: refuse an illegal rollback (target.fingerprint ⊄ applied, unknown ids).
        $this->rollbackGuard->assertLegal($target, new EnvironmentName($env));

        $live = $this->targets->target($definition->host)->status(new EnvironmentName($env));
        $state = $this->stateBuilder->build($definition, $target, $live->color, $live->imageDigest);
        $plan = $this->targets->target($definition->host)->plan(new DeployContext($definition, $target, $state));

        $status = $this->targets->target($definition->host)->rollback($plan, new EnvironmentName($env), $target);

        $this->auditRecorder?->rolledBack(
            $env,
            $actorId,
            $actorIdentitySource,
            $live->imageDigest !== '' ? $live->imageDigest : 'unknown',
            $target->buildId,
            null,
        );

        return DeployOutcome::rolledBack(
            $env,
            sprintf('rolled back to build %s', $target->buildId),
            $plan,
            $status,
        );
    }

    public function rollbackToPrevious(string $env): DeployOutcome
    {
        return $this->rollback($env, null);
    }

    public function previewFor(DeployOutcome $outcome): string
    {
        if ($outcome->plan === null) {
            return '';
        }

        return $this->planRenderer->toText($outcome->plan);
    }
}
