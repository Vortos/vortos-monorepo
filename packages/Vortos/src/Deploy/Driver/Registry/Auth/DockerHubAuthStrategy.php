<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Registry\Auth;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Registry\Auth\RegistryAuthCapability;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\BasicAuthCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('docker-hub')]
final class DockerHubAuthStrategy implements RegistryAuthStrategyInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RegistryAuthCapability::OidcExchange->value => false,
        ]);
    }

    public function supports(RegistryCredential $credential): bool
    {
        return $credential instanceof BasicAuthCredential;
    }

    public function login(CommandRunnerInterface $runner, RegistryCredential $credential): void
    {
        if (!$credential instanceof BasicAuthCredential) {
            throw new \InvalidArgumentException(sprintf(
                '%s requires BasicAuthCredential, got %s.',
                self::class,
                $credential::class,
            ));
        }

        $result = $runner->run(
            ['docker', 'login', 'docker.io', '--username', $credential->username, '--password-stdin'],
            stdin: $credential->password->reveal(),
            redactTokens: $this->redactTokens($credential),
        );
        $result->throwOnFailure('docker login docker.io');
    }

    public function redactTokens(RegistryCredential $credential): array
    {
        if (!$credential instanceof BasicAuthCredential) {
            return [];
        }

        return [$credential->password->reveal()];
    }
}
