<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class ContainerRegistryConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createRegistry(): ContainerRegistryInterface;

    protected function createDriver(): ContainerRegistryInterface
    {
        return $this->createRegistry();
    }

    final public function test_registry_declares_digest_pin_capability(): void
    {
        $caps = $this->createRegistry()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(RegistryCapability::DigestPin->value, $caps);
    }

    final public function test_registry_declares_multi_arch_capability(): void
    {
        $caps = $this->createRegistry()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(RegistryCapability::MultiArch->value, $caps);
    }
}
