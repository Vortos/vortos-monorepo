<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Registry;

use Vortos\Deploy\Driver\Oci\OciOps;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\ContainerRegistryInterface;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Registry\RegistryCapability;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Container registry driver for GitHub Container Registry (ghcr.io).
 *
 * Inject a PatTokenCredential to activate authentication. When null, docker must
 * already be logged in (ambient auth — useful in local dev or CI with GITHUB_TOKEN
 * pre-configured outside Vortos).
 */
#[AsDriver('ghcr')]
final class GhcrRegistry implements ContainerRegistryInterface
{
    use OciOps;

    public function __construct(
        private readonly CommandRunnerInterface $runner,
        private readonly ImageSignerInterface $signer,
        private readonly ?PatTokenCredential $credential = null,
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
            ['docker', 'login', 'ghcr.io', '--username', $this->credential->username, '--password-stdin'],
            stdin: $this->credential->token->reveal(),
            redactTokens: $this->redactTokens(),
        );
        $result->throwOnFailure('docker login ghcr.io');
    }

    private function redactTokens(): array
    {
        if ($this->credential === null) {
            return [];
        }

        return [$this->credential->token->reveal()];
    }
}
