<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Registry\Auth;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Registry\Auth\RegistryAuthCapability;
use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('ghcr')]
final class GhcrAuthStrategy implements RegistryAuthStrategyInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RegistryAuthCapability::OidcExchange->value => false,
        ]);
    }

    public function supports(RegistryCredential $credential): bool
    {
        return $credential instanceof PatTokenCredential;
    }

    public function login(CommandRunnerInterface $runner, RegistryCredential $credential): void
    {
        if (!$credential instanceof PatTokenCredential) {
            throw new \InvalidArgumentException(sprintf(
                '%s requires PatTokenCredential, got %s.',
                self::class,
                $credential::class,
            ));
        }

        $result = $runner->run(
            ['docker', 'login', 'ghcr.io', '--username', $credential->username, '--password-stdin'],
            stdin: $credential->token->reveal(),
            redactTokens: $this->redactTokens($credential),
        );
        $result->throwOnFailure('docker login ghcr.io');
    }

    public function redactTokens(RegistryCredential $credential): array
    {
        if (!$credential instanceof PatTokenCredential) {
            return [];
        }

        return [$credential->token->reveal()];
    }
}
