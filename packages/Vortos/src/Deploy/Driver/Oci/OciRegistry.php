<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Oci;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Zero-credential OCI driver.
 *
 * Use this when docker is already authenticated via ambient credentials
 * (instance metadata, ~/.docker/config.json, or a pre-run docker login).
 * For GHCR, Docker Hub, or GCP Artifact Registry, use the matching typed driver instead.
 */
#[AsDriver('oci')]
final class OciRegistry implements ContainerRegistryInterface
{
    use OciOps;

    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly ImageSignerInterface $signer,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RegistryCapability::DigestPin->value => true,
            RegistryCapability::MultiArch->value => true,
            RegistryCapability::VulnerabilityScan->value => false,
            RegistryCapability::ImageSigning->value => false,
        ]);
    }

    private function authenticate(): void
    {
        // Ambient auth — no credentials managed by this driver.
    }

    private function redactTokens(): array
    {
        return [];
    }
}
