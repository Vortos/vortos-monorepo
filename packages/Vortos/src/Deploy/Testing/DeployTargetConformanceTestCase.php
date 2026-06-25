<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Target\DeployCapability;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class DeployTargetConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createTarget(): DeployTargetInterface;

    protected function createDriver(): DeployTargetInterface
    {
        return $this->createTarget();
    }

    final public function test_target_declares_blue_green_capability(): void
    {
        $caps = $this->createTarget()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(DeployCapability::BlueGreen->value, $caps);
    }

    final public function test_target_declares_rolling_capability(): void
    {
        $caps = $this->createTarget()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(DeployCapability::RollingAcrossNodes->value, $caps);
    }

    final public function test_target_declares_health_gate_capability(): void
    {
        $caps = $this->createTarget()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(DeployCapability::HealthGate->value, $caps);
    }

    final public function test_target_declares_auto_rollback_capability(): void
    {
        $caps = $this->createTarget()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(DeployCapability::AutoRollback->value, $caps);
    }
}
