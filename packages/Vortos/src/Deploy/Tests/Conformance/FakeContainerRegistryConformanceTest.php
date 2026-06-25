<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Testing\ContainerRegistryConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;

final class FakeContainerRegistryConformanceTest extends ContainerRegistryConformanceTestCase
{
    protected function createRegistry(): ContainerRegistryInterface
    {
        return new FakeContainerRegistry();
    }

    protected function expectedKey(): string
    {
        return 'fake-registry';
    }

    public function test_honestly_reports_no_multi_arch(): void
    {
        $registry = new FakeContainerRegistry();
        $this->assertHonestlyUnsupported($registry->capabilities(), 'multi_arch');
    }

    public function test_honestly_reports_no_vulnerability_scan(): void
    {
        $registry = new FakeContainerRegistry();
        $this->assertHonestlyUnsupported($registry->capabilities(), 'vulnerability_scan');
    }
}
