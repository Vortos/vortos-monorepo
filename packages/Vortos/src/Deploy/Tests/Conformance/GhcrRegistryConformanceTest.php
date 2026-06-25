<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\Registry\GhcrRegistry;
use Vortos\Deploy\Oci\NullImageSigner;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\Deploy\Testing\ContainerRegistryConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;

final class GhcrRegistryConformanceTest extends ContainerRegistryConformanceTestCase
{
    protected function createRegistry(): ContainerRegistryInterface
    {
        return new GhcrRegistry(
            runner: new FakeCommandRunner(),
            signer: new NullImageSigner(),
        );
    }

    protected function expectedKey(): string
    {
        return 'ghcr';
    }

    public function test_honestly_unsupported_vulnerability_scan(): void
    {
        $descriptor = $this->createRegistry()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, RegistryCapability::VulnerabilityScan);
    }

    public function test_honestly_unsupported_image_signing(): void
    {
        $descriptor = $this->createRegistry()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, RegistryCapability::ImageSigning);
    }
}
