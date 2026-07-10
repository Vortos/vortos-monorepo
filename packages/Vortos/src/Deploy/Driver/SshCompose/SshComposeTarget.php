<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCompose;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Exception\ImageNotAvailableException;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\DesiredImage;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Rollback\RollbackGuard;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStateStoreInterface;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Target\TargetStatus;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\ReadModel\ManifestReadModelInterface;

#[AsDriver('ssh-compose')]
final class SshComposeTarget implements DeployTargetInterface
{
    public function __construct(
        private readonly DeployPlanner $planner,
        private readonly StepExecutor $executor,
        private readonly ContainerRegistryInterface $registry,
        private readonly DeployStateStoreInterface $stateStore,
        private readonly CurrentReleaseStoreInterface $releaseStore,
        private readonly ImageReclaimer $reclaimer,
        private readonly ?RollbackGuard $rollbackGuard = null,
        private readonly ?ManifestReadModelInterface $manifestReadModel = null,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return SshComposeCapability::descriptor();
    }

    public function plan(DeployContext $context): DeployPlan
    {
        return $this->planner->plan($context);
    }

    public function assertImageAvailable(ImageReference $image): void
    {
        if (!$image->isDigestPinned()) {
            throw ImageNotAvailableException::notFound($image->toString(), 'reference is not digest-pinned');
        }

        try {
            $liveDigest = $this->registry->digestFor($image);
        } catch (\Throwable $e) {
            throw ImageNotAvailableException::notFound($image->toString(), $e->getMessage());
        }

        if ($liveDigest !== $image->digest) {
            throw ImageNotAvailableException::digestMismatch($image->toString(), (string) $image->digest, $liveDigest);
        }
    }

    public function migrate(DeployPlan $plan): void
    {
    }

    public function release(DeployPlan $plan, EnvironmentName $env): TargetStatus
    {
        return $this->executePlan($plan, $env);
    }

    /**
     * Rolling back is "release a plan whose desired state is the previous build" —
     * {@see \Vortos\Deploy\Runner\RollbackRunner} already builds that plan via the same
     * planner used for forward deploys before calling here. Executing it through the
     * same {@see executePlan()} path (rather than discarding it) is what actually stops
     * the bad color and switches upstream back.
     */
    public function rollback(DeployPlan $plan, EnvironmentName $env, ?BuildManifest $targetManifest = null): TargetStatus
    {
        if ($this->rollbackGuard !== null && $targetManifest !== null) {
            $this->rollbackGuard->assertLegal($targetManifest, $env);
        }

        return $this->executePlan($plan, $env);
    }

    public function status(EnvironmentName $env): TargetStatus
    {
        $release = $this->releaseStore->currentRelease($env->value);

        if ($release === null) {
            return new TargetStatus(
                color: ActiveColor::None,
                imageDigest: '',
                healthStatus: 'unknown',
                checkedAt: new \DateTimeImmutable(),
            );
        }

        return new TargetStatus(
            color: $release->activeColor,
            imageDigest: $release->imageDigest,
            healthStatus: 'ok',
            checkedAt: new \DateTimeImmutable(),
        );
    }

    private function executePlan(DeployPlan $plan, EnvironmentName $env): TargetStatus
    {
        $envValue = $env->value;
        $planHash = $plan->planHash->toString();

        $existingRun = $this->stateStore->find($envValue, $planHash);
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
            env: $envValue,
            planHash: $planHash,
            definitionHash: $plan->definitionHash,
            desiredDigest: DesiredImage::digestFromPlan($plan),
            desiredRepository: DesiredImage::repositoryFromPlan($plan),
            status: DeployStatus::Pending,
        );

        if ($existingRun === null) {
            $this->stateStore->begin($run);
        }

        $image = DesiredImage::fromPlan($plan);
        if ($image === null) {
            // A plan with steps but no digest-pinned image is a defect — fail closed
            // rather than deploy a bogus reference. A truly empty plan is a legitimate
            // no-op (e.g. a guard-only rollback rehearsal): mark complete and return.
            if (!$plan->isEmpty()) {
                throw ImageNotAvailableException::notFound(
                    $run->desiredRepository !== '' ? $run->desiredRepository : '(none)',
                    'plan carries no digest-pinned image_repository/image_digest — refusing to release',
                );
            }

            $this->stateStore->complete($run->runId);

            return new TargetStatus(
                color: ActiveColor::from((string) ($this->extractPromotedColor($run) ?? ActiveColor::None->value)),
                imageDigest: $run->desiredDigest,
                healthStatus: 'ok',
                checkedAt: new \DateTimeImmutable(),
            );
        }

        try {
            $this->executor->execute($plan, $run, $image);
            $this->stateStore->complete($run->runId);
        } catch (\Throwable $e) {
            $this->stateStore->fail($run->runId, $e->getMessage());

            throw $e;
        } finally {
            // R8-4: reclaim superseded release images + build cache on EVERY deploy attempt —
            // success AND failure. A failed deploy leaves a freshly-pulled but never-promoted image
            // orphaned on disk exactly where reclaim used to be skipped (it ran only after the
            // success path); running it in a finally block closes that leak so disk stays bounded whether
            // CI is green or red. The keep-set is reference-counted (live release + previous-for-
            // rollback digest + container-referenced images + recency floor), so the failure's
            // orphan is removed while the rollback target is protected. Best-effort by construction:
            // a reclaim error is swallowed here so it can neither fail a green deploy nor mask the
            // exception propagating out of a red one.
            $this->reclaimImagesSafely($plan, $env, $image);
        }

        return new TargetStatus(
            color: ActiveColor::from((string) ($this->extractPromotedColor($run) ?? ActiveColor::None->value)),
            imageDigest: $run->desiredDigest,
            healthStatus: 'ok',
            checkedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Best-effort reclaim wrapper: applies the plan's ImagePrunePolicy through the reference-counted
     * {@see ImageReclaimer}, computing the release-authoritative protected-digest set. Never throws —
     * it runs inside the deploy's a finally block, so an error must not become the deploy's outcome.
     */
    private function reclaimImagesSafely(DeployPlan $plan, EnvironmentName $env, ImageReference $image): void
    {
        $policy = $plan->imagePrunePolicy;
        if ($policy === null || !$policy->enabled) {
            return;
        }

        try {
            $this->reclaimer->reclaim($image->repository, $policy, $this->protectedDigests($env));
        } catch (\Throwable) {
            // Reclaim is disk hygiene, never a release gate — swallow.
        }
    }

    /**
     * Registry digests that must never be reclaimed: the current live release and the
     * previous-for-rollback. These mirror the exact authority the rollback path uses
     * ({@see ManifestReadModelInterface::previousForEnvironment()}), so the keep-set can never
     * evict an image a rollback could target.
     *
     * @return list<string>
     */
    private function protectedDigests(EnvironmentName $env): array
    {
        $digests = [];

        $current = $this->releaseStore->currentRelease($env->value);
        if ($current !== null && $current->imageDigest !== '') {
            $digests[] = $current->imageDigest;
        }

        $previous = $this->manifestReadModel?->previousForEnvironment($env->value);
        if ($previous !== null) {
            $digests[] = $previous->imageDigest;
        }

        return array_values(array_unique(array_filter($digests)));
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
