<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Driver\Oci\OciRegistry;
use Vortos\Deploy\Oci\NullImageSigner;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\Deploy\Testing\ContainerRegistryConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;

final class OciRegistryConformanceTest extends ContainerRegistryConformanceTestCase
{
    protected function createRegistry(): ContainerRegistryInterface
    {
        return new OciRegistry(
            runner: new FakeCommandRunner(),
            signer: new NullImageSigner(),
        );
    }

    protected function expectedKey(): string
    {
        return 'oci';
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
