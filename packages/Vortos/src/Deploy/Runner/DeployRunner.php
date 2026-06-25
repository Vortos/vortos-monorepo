<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

use Vortos\Deploy\Audit\DeployAuditRecorder;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Preflight\DeployDoctor;
use Vortos\Deploy\Preflight\PreflightContextFactory;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Target\DeployTargetRegistry;

/**
 * 'deploy' orchestration — a thin coordinator over already-tested pieces.
 *
 * The flow is fail-closed by construction:
 *
 *   build context (FAIL if no desired build) → run doctor → if NOT clear, refuse
 *   (mutate nothing) → plan + preview → if dry-run, stop (mutate nothing) →
 *   push + release → on failure, auto-rollback (when enabled) via the single
 *   {@see RollbackRunner} path.
 *
 * The only two mutating calls are {@see \Vortos\Deploy\Target\DeployTargetInterface::push()}
 * and {@see \Vortos\Deploy\Target\DeployTargetInterface::release()}; both are reached
 * only on the live, doctor-clear path — which is what makes '--dry-run' and "refuse"
 * mutate nothing.
 */
final class DeployRunner
{
    public function __construct(
        private readonly PreflightContextFactory $contextFactory,
        private readonly DeployTargetRegistry $targets,
        private readonly PlanRenderer $planRenderer,
        private readonly DeployDoctor $doctor,
        private readonly RollbackRunner $rollbackRunner,
        private readonly ?DeployAuditRecorder $auditRecorder = null,
    ) {}

    public function run(DeployRequest $request): DeployOutcome
    {
        $env = $request->env;

        // Read-only: resolves config, reads the manifest, queries live status.
        $context = $this->contextFactory->build($env);
        $definition = $context->definition;
        $manifest = $context->desiredManifest;

        $this->auditRecorder?->attempted(
            $env,
            $request->actorId,
            $request->actorIdentitySource,
            $manifest->buildId,
            $manifest->gitSha,
            $manifest->imageDigest,
            $manifest->schemaFingerprint->hash,
            null,
        );

        $report = $this->doctor->run($context);

        // Fail-closed: never deploy through a red gate. Nothing has mutated.
        if (!$report->isClear()) {
            $this->auditRecorder?->refused(
                $env,
                $request->actorId,
                $request->actorIdentitySource,
                $manifest->buildId,
                $manifest->gitSha,
                $manifest->imageDigest,
                $manifest->schemaFingerprint->hash,
                null,
                $this->failedCheckIds($report),
            );

            return DeployOutcome::refused($env, $report);
        }

        $target = $this->targets->target($definition->host);
        $plan = $target->plan(new DeployContext($definition, $manifest, $context->currentState));
        $preview = $this->planRenderer->toText($plan);

        if ($request->isDryRun()) {
            // Rehearsal only — push()/release() are never reached, so nothing mutates.
            return DeployOutcome::dryRun($env, $report, $plan, $preview);
        }

        $digest = $request->imageDigest ?? $manifest->imageDigest;
        $image = new ImageReference('app', digest: $digest);

        try {
            $target->push($image);
            $status = $target->release($plan);

            $this->auditRecorder?->succeeded(
                $env,
                $request->actorId,
                $request->actorIdentitySource,
                $manifest->buildId,
                $manifest->gitSha,
                $manifest->imageDigest,
                $manifest->schemaFingerprint->hash,
                null,
                $status->toArray() === [] ? 'released' : json_encode($status->toArray(), JSON_THROW_ON_ERROR),
            );

            return DeployOutcome::deployed($env, $plan, $preview, $status, $report);
        } catch (\Throwable $e) {
            $this->auditRecorder?->failed(
                $env,
                $request->actorId,
                $request->actorIdentitySource,
                $manifest->buildId,
                $manifest->gitSha,
                $manifest->imageDigest,
                $manifest->schemaFingerprint->hash,
                null,
                $e::class,
                $e->getMessage(),
            );

            if ($definition->autoRollback) {
                $rollback = $this->rollbackRunner->rollback(
                    $env,
                    null,
                    $request->actorId,
                    $request->actorIdentitySource,
                );

                return DeployOutcome::rolledBack(
                    $env,
                    sprintf('deploy failed (%s); auto-rolled-back', $e->getMessage()),
                    $rollback->plan,
                    $rollback->targetStatus,
                    $report,
                );
            }

            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    private function failedCheckIds(\Vortos\Deploy\Preflight\PreflightReport $report): array
    {
        return array_map(
            static fn ($finding) => $finding->id,
            $report->failures(),
        );
    }
}
