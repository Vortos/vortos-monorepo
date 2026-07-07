<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

use Vortos\Deploy\Audit\DeployAuditRecorder;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Execution\SshConnectionActivator;
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
        private readonly ?SshConnectionActivator $connectionActivator = null,
        private readonly ?\Vortos\Deploy\Runtime\MigrationAutoPublisherInterface $autoPublisher = null,
    ) {}

    public function run(DeployRequest $request): DeployOutcome
    {
        $env = $request->env;

        // Read-only: resolves config, reads the manifest, queries live status.
        $context = $this->contextFactory->build($env);
        $definition = $context->definition;
        $manifest = $context->desiredManifest;

        // R8-1: opt-in auto-publish runs BEFORE the doctor gate, and only for a live deploy — a
        // dry-run must mutate nothing, including the migration tree. Enabled by the CLI flag OR the
        // config/deploy.php default. On failure the deploy is refused (the exception propagates to
        // the caller), never proceeding half-published.
        $autoPublish = $request->autoPublishMigrations || $definition->autoPublishMigrations;
        if ($autoPublish && !$request->isDryRun() && $this->autoPublisher !== null) {
            $this->autoPublisher->publish();
        }

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
        $repository = $request->imageRepository ?? $manifest->imageRepository;
        $image = new ImageReference($repository, digest: $digest);

        // The mutating section: image verify → release → (on failure) auto-rollback. In the
        // push model this must run with an active SSH connection, so it is wrapped by the
        // connection activator (which leases the credential, activates the transport, and
        // tears both down afterwards). In local/pull mode the activator is absent and this
        // runs directly.
        $deploy = fn (): DeployOutcome => $this->executeMutating($request, $definition, $target, $plan, $image, $preview, $report, $manifest);

        if ($this->connectionActivator !== null) {
            return $this->connectionActivator->withConnection($definition, new EnvironmentName($env), $deploy);
        }

        return $deploy();
    }

    private function executeMutating(
        DeployRequest $request,
        \Vortos\Deploy\Definition\DeploymentDefinition $definition,
        \Vortos\Deploy\Target\DeployTargetInterface $target,
        \Vortos\Deploy\Plan\DeployPlan $plan,
        ImageReference $image,
        string $preview,
        \Vortos\Deploy\Preflight\PreflightReport $report,
        \Vortos\Release\Manifest\BuildManifest $manifest,
    ): DeployOutcome {
        $env = $request->env;

        try {
            // Fail-closed: the build job is the only pusher; verify the pinned image is
            // actually present in its registry before mutating anything on the target.
            $target->assertImageAvailable($image);
            $status = $target->release($plan, new EnvironmentName($env));

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
