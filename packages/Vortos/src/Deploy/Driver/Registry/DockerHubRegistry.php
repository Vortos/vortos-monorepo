<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Registry;

use Vortos\Deploy\Driver\Oci\OciOps;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Container registry driver for Docker Hub (docker.io).
 *
 * Inject a BasicAuthCredential with a Docker Hub username and access token
 * (not password — Docker Hub requires an access token for automated use).
 * When null, docker must already be logged in via ambient credentials.
 */
#[AsDriver('docker-hub')]
final class DockerHubRegistry implements ContainerRegistryInterface
{
    use OciOps;

    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly ImageSignerInterface $signer,
        private readonly ?BasicAuthCredential $credential = null,
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
            ['docker', 'login', 'docker.io', '--username', $this->credential->username, '--password-stdin'],
            stdin: $this->credential->password->reveal(),
            redactTokens: $this->redactTokens(),
        );
        $result->throwOnFailure('docker login docker.io');
    }

    private function redactTokens(): array
    {
        if ($this->credential === null) {
            return [];
        }

        return [$this->credential->password->reveal()];
    }
}
