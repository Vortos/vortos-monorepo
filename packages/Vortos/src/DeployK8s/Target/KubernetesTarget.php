<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Target;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Target\TargetStatus;
use Vortos\DeployK8s\Api\KubeApiInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Release\Manifest\BuildManifest;

#[AsDriver('k8s')]
final class KubernetesTarget implements DeployTargetInterface
{
    public function __construct(
        private readonly DeployPlanner $planner,
        private readonly KubernetesStepExecutor $executor,
        private readonly ContainerRegistryInterface $registry,
        private readonly DeployStateStoreInterface $stateStore,
        private readonly KubeApiInterface $kubeApi,
        private readonly ?RollbackGuard $rollbackGuard = null,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return KubernetesCapability::descriptor();
    }

    public function plan(DeployContext $context): DeployPlan
    {
        return $this->planner->plan($context);
    }

    public function push(ImageReference $image): ImageReference
    {
        return $this->registry->push($image);
    }

    public function migrate(DeployPlan $plan): void
    {
    }

    public function release(DeployPlan $plan): TargetStatus
    {
        return $this->executePlan($plan);
    }

    public function rollback(DeployPlan $plan, ?BuildManifest $targetManifest = null): TargetStatus
    {
        if ($this->rollbackGuard !== null && $targetManifest !== null) {
            $this->rollbackGuard->assertLegal($targetManifest, new EnvironmentName('production'));
        }

        return $this->executePlan($plan);
    }

    public function status(EnvironmentName $env): TargetStatus
    {
        $svc = $this->kubeApi->getService('app', 'default');

        if ($svc === null) {
            return new TargetStatus(
                color: ActiveColor::None,
                imageDigest: '',
                healthStatus: 'unknown',
                checkedAt: new \DateTimeImmutable(),
            );
        }

        $colorStr = $svc->selector['app.kubernetes.io/color'] ?? 'none';
        $color = ActiveColor::tryFrom($colorStr) ?? ActiveColor::None;

        $deploymentName = 'app-' . $colorStr;
        $rollout = $this->kubeApi->rolloutStatus('Deployment', $deploymentName, $svc->namespace);

        return new TargetStatus(
            color: $color,
            imageDigest: $rollout->imageDigest,
            healthStatus: $rollout->ready ? 'ok' : 'degraded',
            checkedAt: new \DateTimeImmutable(),
        );
    }

    private function executePlan(DeployPlan $plan): TargetStatus
    {
        $env = 'production';
        $planHash = $plan->planHash->toString();

        $existingRun = $this->stateStore->find($env, $planHash);
        if ($existingRun !== null && $existingRun->status === DeployStatus::Completed) {
            return new TargetStatus(
                color: ActiveColor::from((string) ($this->extractPromotedColor($existingRun) ?? ActiveColor::None->value)),
                imageDigest: $existingRun->desiredDigest,
                healthStatus: 'ok',
                checkedAt: new \DateTimeImmutable(),
            );
        }

        $run = $existingRun ?? new DeployRun(
            runId: $this->generateRunId(),
            env: $env,
            planHash: $planHash,
            definitionHash: $plan->definitionHash,
            desiredDigest: $this->extractDesiredDigest($plan),
            status: DeployStatus::Pending,
        );

        if ($existingRun === null) {
            $this->stateStore->begin($run);
        }

        $image = $this->buildImageReference($run->desiredDigest);

        try {
            $this->executor->execute($plan, $run, $image);
            $this->stateStore->complete($run->runId);
        } catch (\Throwable $e) {
            $this->stateStore->fail($run->runId, $e->getMessage());
            throw $e;
        }

        return new TargetStatus(
            color: ActiveColor::from((string) ($this->extractPromotedColor($run) ?? ActiveColor::None->value)),
            imageDigest: $run->desiredDigest,
            healthStatus: 'ok',
            checkedAt: new \DateTimeImmutable(),
        );
    }

    private function extractDesiredDigest(DeployPlan $plan): string
    {
        foreach ($plan->phases as $phase) {
            foreach ($phase->steps as $step) {
                if (isset($step->params['image_digest']) && \is_string($step->params['image_digest'])) {
                    return $step->params['image_digest'];
                }
            }
        }

        return '';
    }

    private function extractPromotedColor(DeployRun $run): ?string
    {
        foreach ($run->outcomes() as $outcome) {
            if (str_contains($outcome->result, 'promoted color=')) {
                if (preg_match('/promoted color=(\w+)/', $outcome->result, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    private function buildImageReference(string $digest): ImageReference
    {
        if ($digest === '' || !str_starts_with($digest, 'sha256:')) {
            return new ImageReference('app', digest: null);
        }

        return new ImageReference('app', digest: $digest);
    }

    private function generateRunId(): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6)),
        );
    }
}
