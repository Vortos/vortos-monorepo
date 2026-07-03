<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Target\TargetStatus;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * A deploy target that records every mutating call — the spy that makes
 * "dry-run / refuse mutates nothing" and "live deploys once" tested invariants.
 * Can be configured to throw on release() to exercise the auto-rollback path.
 */
#[AsDriver('fake-target')]
final class RecordingDeployTarget implements DeployTargetInterface
{
    public int $planCalls = 0;
    public int $assertImageAvailableCalls = 0;
    public int $releaseCalls = 0;
    public int $rollbackCalls = 0;

    public function __construct(private readonly bool $failRelease = false)
    {
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            DeployCapability::BlueGreen->value => true,
            DeployCapability::HealthGate->value => true,
        ], ['target_arch' => 'linux/arm64']);
    }

    public function plan(DeployContext $context): DeployPlan
    {
        $this->planCalls++;

        $phases = (new \Vortos\Deploy\Strategy\BlueGreenStrategy())->phases($context);

        return new DeployPlan($phases, $context->definition->definitionHash);
    }

    public function assertImageAvailable(ImageReference $image): void
    {
        $this->assertImageAvailableCalls++;
    }

    public function migrate(DeployPlan $plan): void {}

    public function release(DeployPlan $plan, EnvironmentName $env): TargetStatus
    {
        $this->releaseCalls++;
        if ($this->failRelease) {
            throw new \RuntimeException('release health gate failed');
        }

        return new TargetStatus(ActiveColor::Green, 'sha256:' . str_repeat('a', 64), 'healthy', new \DateTimeImmutable());
    }

    public function rollback(DeployPlan $plan, EnvironmentName $env, ?BuildManifest $targetManifest = null): TargetStatus
    {
        $this->rollbackCalls++;

        return new TargetStatus(ActiveColor::Blue, 'sha256:' . str_repeat('b', 64), 'healthy', new \DateTimeImmutable());
    }

    public function status(EnvironmentName $env): TargetStatus
    {
        return new TargetStatus(ActiveColor::Blue, 'sha256:' . str_repeat('a', 64), 'healthy', new \DateTimeImmutable());
    }
}
