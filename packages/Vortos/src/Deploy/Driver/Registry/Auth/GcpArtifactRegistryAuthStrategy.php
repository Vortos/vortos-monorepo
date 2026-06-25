<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Registry\Auth;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Registry\Auth\RegistryAuthCapability;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\GcpServiceAccountCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Authenticates to GCP Artifact Registry using a service account JSON key.
 *
 * docker login uses the fixed username "_json_key" with the full JSON as the password,
 * passed via stdin. The registry host is the regional AR endpoint
 * (e.g. "europe-west4-docker.pkg.dev").
 */
#[AsDriver('gcp-artifact-registry')]
final class GcpArtifactRegistryAuthStrategy implements RegistryAuthStrategyInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RegistryAuthCapability::OidcExchange->value => false,
        ]);
    }

    public function supports(RegistryCredential $credential): bool
    {
        return $credential instanceof GcpServiceAccountCredential;
    }

    public function login(CommandRunnerInterface $runner, RegistryCredential $credential): void
    {
        if (!$credential instanceof GcpServiceAccountCredential) {
            throw new \InvalidArgumentException(sprintf(
                '%s requires GcpServiceAccountCredential, got %s.',
                self::class,
                $credential::class,
            ));
        }

        $result = $runner->run(
            ['docker', 'login', $credential->registryHost, '--username', '_json_key', '--password-stdin'],
            stdin: $credential->serviceAccountJson->reveal(),
            redactTokens: $this->redactTokens($credential),
        );
        $result->throwOnFailure(sprintf('docker login %s', $credential->registryHost));
    }

    public function redactTokens(RegistryCredential $credential): array
    {
        if (!$credential instanceof GcpServiceAccountCredential) {
            return [];
        }

        return [$credential->serviceAccountJson->reveal()];
    }
}
