<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\SshCompose\SshComposeCapability;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\DeployK8s\Target\KubernetesCapability;

final class RollingCapabilityHonestyTest extends TestCase
{
    public function test_k8s_declares_rolling_across_nodes_true(): void
    {
        $descriptor = KubernetesCapability::descriptor();
        $this->assertTrue($descriptor->supports(DeployCapability::RollingAcrossNodes));
    }

    public function test_ssh_compose_declares_rolling_across_nodes_false(): void
    {
        $descriptor = SshComposeCapability::descriptor();
        $this->assertFalse($descriptor->supports(DeployCapability::RollingAcrossNodes));
    }

    public function test_k8s_declares_canary_true(): void
    {
        $descriptor = KubernetesCapability::descriptor();
        $this->assertTrue($descriptor->supports(DeployCapability::Canary));
    }

    public function test_ssh_compose_declares_canary_false(): void
    {
        $descriptor = SshComposeCapability::descriptor();
        $this->assertFalse($descriptor->supports(DeployCapability::Canary));
    }

    public function test_rolling_strategy_satisfied_by_k8s(): void
    {
        $strategy = new RollingStrategy();
        $required = $strategy->requires();
        $descriptor = KubernetesCapability::descriptor();

        $mismatch = $descriptor->satisfies($required);
        $this->assertNull($mismatch, 'k8s must satisfy rolling strategy requirements.');
    }

    public function test_rolling_strategy_rejected_by_ssh_compose(): void
    {
        $strategy = new RollingStrategy();
        $required = $strategy->requires();
        $descriptor = SshComposeCapability::descriptor();

        $mismatch = $descriptor->satisfies($required);
        $this->assertNotNull($mismatch, 'ssh-compose must NOT satisfy rolling strategy requirements.');
    }
}
