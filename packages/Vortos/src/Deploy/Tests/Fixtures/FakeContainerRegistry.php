<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('fake-registry')]
final class FakeContainerRegistry implements ContainerRegistryInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RegistryCapability::DigestPin->value => true,
            RegistryCapability::MultiArch->value => false,
            RegistryCapability::VulnerabilityScan->value => false,
            RegistryCapability::ImageSigning->value => false,
        ]);
    }

    public function push(ImageReference $image): ImageReference
    {
        return $image->withDigest('sha256:' . str_repeat('a', 64));
    }

    public function pull(ImageReference $image): void {}

    public function tag(ImageReference $image, string $tag): ImageReference
    {
        return $image->withTag($tag);
    }

    public function digestFor(ImageReference $image): string
    {
        return 'sha256:' . str_repeat('a', 64);
    }
}
