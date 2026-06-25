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
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('fake-target')]
final class FakeDeployTarget implements DeployTargetInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            DeployCapability::BlueGreen->value => true,
            DeployCapability::RollingAcrossNodes->value => false,
            DeployCapability::Canary->value => false,
            DeployCapability::HealthGate->value => true,
            DeployCapability::AutoRollback->value => true,
            DeployCapability::ExpandMigrate->value => true,
            DeployCapability::WorkerDrain->value => true,
            DeployCapability::AcceptsDowntime->value => true,
        ], [
            'target_arch' => 'linux/arm64',
        ]);
    }

    public function plan(DeployContext $context): DeployPlan
    {
        return new DeployPlan([], $context->definition->definitionHash);
    }

    public function push(ImageReference $image): ImageReference
    {
        return $image->withDigest('sha256:' . str_repeat('a', 64));
    }

    public function migrate(DeployPlan $plan): void {}

    public function release(DeployPlan $plan): TargetStatus
    {
        return new TargetStatus(
            ActiveColor::Green,
            'sha256:' . str_repeat('a', 64),
            'healthy',
            new \DateTimeImmutable(),
        );
    }

    public function rollback(DeployPlan $plan): TargetStatus
    {
        return new TargetStatus(
            ActiveColor::Blue,
            'sha256:' . str_repeat('b', 64),
            'healthy',
            new \DateTimeImmutable(),
        );
    }

    public function status(EnvironmentName $env): TargetStatus
    {
        return new TargetStatus(
            ActiveColor::Blue,
            'sha256:' . str_repeat('a', 64),
            'healthy',
            new \DateTimeImmutable(),
        );
    }
}
