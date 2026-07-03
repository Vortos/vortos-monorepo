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

#[AsDriver('single-node')]
final class SingleNodeTarget implements DeployTargetInterface
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
        ]);
    }

    public function plan(DeployContext $context): DeployPlan
    {
        return new DeployPlan([], $context->definition->definitionHash);
    }

    public function assertImageAvailable(ImageReference $image): void {}

    public function migrate(DeployPlan $plan): void {}

    public function release(DeployPlan $plan, EnvironmentName $env): TargetStatus
    {
        return new TargetStatus(ActiveColor::Blue, '', 'healthy', new \DateTimeImmutable());
    }

    public function rollback(DeployPlan $plan, EnvironmentName $env, ?BuildManifest $targetManifest = null): TargetStatus
    {
        return new TargetStatus(ActiveColor::Blue, '', 'healthy', new \DateTimeImmutable());
    }

    public function status(EnvironmentName $env): TargetStatus
    {
        return new TargetStatus(ActiveColor::Blue, '', 'healthy', new \DateTimeImmutable());
    }
}
