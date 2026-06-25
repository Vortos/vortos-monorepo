<?php

declare(strict_types=1);

namespace Vortos\Deploy\Definition;

use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Exception\InvalidDeploymentDefinitionException;
use Vortos\Deploy\Exception\StrategyCapabilityException;
use Vortos\Deploy\Exception\UnknownDriverSelectionException;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\OpsKit\Driver\Capability\CapabilityMismatchException;
use Vortos\OpsKit\Driver\Capability\CapabilityValidator;
use Vortos\Release\Manifest\Arch;

final class DeploymentDefinitionValidator
{
    public function __construct(
        private readonly DeployTargetRegistry $targets,
        private readonly ContainerRegistryRegistry $registries,
        private readonly CredentialProviderRegistry $credentials,
        private readonly DeployStrategyRegistry $strategies,
    ) {}

    /** @throws InvalidDeploymentDefinitionException */
    public function validate(DeploymentDefinition $definition): void
    {
        $this->resolveTarget($definition);
        $this->resolveRegistry($definition);
        $this->resolveCredential($definition);
        $this->validateStrategyCapabilities($definition);
        $this->validateArchCapability($definition);
    }

    private function resolveTarget(DeploymentDefinition $definition): void
    {
        if (!$this->targets->has($definition->host)) {
            throw UnknownDriverSelectionException::forKey(
                'deploy-target',
                $definition->host,
                $this->targets->keys(),
            );
        }
    }

    private function resolveRegistry(DeploymentDefinition $definition): void
    {
        if (!$this->registries->has($definition->registry)) {
            throw UnknownDriverSelectionException::forKey(
                'container-registry',
                $definition->registry,
                $this->registries->keys(),
            );
        }
    }

    private function resolveCredential(DeploymentDefinition $definition): void
    {
        if (!$this->credentials->has($definition->credential)) {
            throw UnknownDriverSelectionException::forKey(
                'deploy-credential',
                $definition->credential,
                $this->credentials->keys(),
            );
        }
    }

    private function validateStrategyCapabilities(DeploymentDefinition $definition): void
    {
        if (!$this->strategies->has($definition->strategy)) {
            throw new InvalidDeploymentDefinitionException(sprintf(
                'Unknown strategy "%s". Registered: [%s].',
                $definition->strategy->value,
                implode(', ', $this->strategies->keys()),
            ));
        }

        $strategy = $this->strategies->get($definition->strategy);
        $target = $this->targets->target($definition->host);

        try {
            CapabilityValidator::assertSatisfies(
                $definition->host,
                'deploy-target',
                $target->capabilities(),
                $strategy->requires(),
            );
        } catch (CapabilityMismatchException $e) {
            throw StrategyCapabilityException::fromMismatch(
                $definition->strategy,
                $definition->host,
                $e,
            );
        }
    }

    private function validateArchCapability(DeploymentDefinition $definition): void
    {
        $target = $this->targets->target($definition->host);
        $descriptor = $target->capabilities();

        $declaredArch = $descriptor->constraint('target_arch');
        if ($declaredArch === null) {
            return;
        }

        if ((string) $declaredArch !== $definition->arch->value) {
            throw new InvalidDeploymentDefinitionException(sprintf(
                'Architecture mismatch: definition declares "%s" but target "%s" declares arch constraint "%s".',
                $definition->arch->value,
                $definition->host,
                (string) $declaredArch,
            ));
        }
    }
}
