<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Registry;

use Vortos\Deploy\Driver\Oci\OciOps;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\GcpServiceAccountCredential;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Container registry driver for GCP Artifact Registry.
 *
 * Authenticates using a service account JSON key injected as a GcpServiceAccountCredential.
 * The credential carries the regional AR host (e.g. "europe-west4-docker.pkg.dev").
 * When null, docker must already be authenticated (e.g. via Workload Identity on GKE).
 */
#[AsDriver('gcp-artifact-registry')]
final class GcpArtifactRegistry implements ContainerRegistryInterface
{
    use OciOps;

    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly ImageSignerInterface $signer,
        private readonly ?GcpServiceAccountCredential $credential = null,
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
        if ($this->credential === null) {
            return;
        }

        $result = $this->runner->run(
            ['docker', 'login', $this->credential->registryHost, '--username', '_json_key', '--password-stdin'],
            stdin: $this->credential->serviceAccountJson->reveal(),
            redactTokens: $this->redactTokens(),
        );
        $result->throwOnFailure(sprintf('docker login %s', $this->credential->registryHost));
    }

    private function redactTokens(): array
    {
        if ($this->credential === null) {
            return [];
        }

        return [$this->credential->serviceAccountJson->reveal()];
    }
}
