<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\DeploymentDefinitionValidator;
use Vortos\Deploy\Exception\InvalidDeploymentDefinitionException;
use Vortos\Deploy\Exception\StrategyCapabilityException;
use Vortos\Deploy\Exception\UnknownDriverSelectionException;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeCredentialProvider;
use Vortos\Deploy\Tests\Fixtures\FakeDeployTarget;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Deploy\Tests\Fixtures\SingleNodeTarget;

final class DeploymentDefinitionValidatorTest extends TestCase
{
    public function test_valid_definition_passes(): void
    {
        $validator = $this->createValidator();
        $def = DeploymentDefinition::create()
            ->host('fake-target')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->build();

        $validator->validate($def);
        $this->addToAssertionCount(1);
    }

    public function test_unknown_host_key_rejects(): void
    {
        $validator = $this->createValidator();
        $def = DeploymentDefinition::create()
            ->host('nonexistent-target')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->build();

        $this->expectException(UnknownDriverSelectionException::class);
        $this->expectExceptionMessage('Unknown deploy-target driver "nonexistent-target"');

        $validator->validate($def);
    }

    public function test_unknown_registry_key_rejects(): void
    {
        $validator = $this->createValidator();
        $def = DeploymentDefinition::create()
            ->host('fake-target')
            ->registry('nonexistent-registry')
            ->credential('fake-credential')
            ->build();

        $this->expectException(UnknownDriverSelectionException::class);
        $this->expectExceptionMessage('Unknown container-registry driver "nonexistent-registry"');

        $validator->validate($def);
    }

    public function test_unknown_credential_key_rejects(): void
    {
        $validator = $this->createValidator();
        $def = DeploymentDefinition::create()
            ->host('fake-target')
            ->registry('fake-registry')
            ->credential('nonexistent-cred')
            ->build();

        $this->expectException(UnknownDriverSelectionException::class);
        $this->expectExceptionMessage('Unknown deploy-credential driver "nonexistent-cred"');

        $validator->validate($def);
    }

    public function test_rolling_strategy_on_single_node_rejects(): void
    {
        $validator = $this->createValidator(useSingleNode: true);
        $def = DeploymentDefinition::create()
            ->host('single-node')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->strategy('rolling')
            ->build();

        $this->expectException(StrategyCapabilityException::class);
        $this->expectExceptionMessage('Strategy "rolling" is incompatible with target "single-node"');

        $validator->validate($def);
    }

    public function test_arch_mismatch_rejects(): void
    {
        $validator = $this->createValidator();
        $def = DeploymentDefinition::create()
            ->host('fake-target')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->arch('linux/amd64')
            ->build();

        $this->expectException(InvalidDeploymentDefinitionException::class);
        $this->expectExceptionMessage('Architecture mismatch');

        $validator->validate($def);
    }

    public function test_arch_match_passes(): void
    {
        $validator = $this->createValidator();
        $def = DeploymentDefinition::create()
            ->host('fake-target')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->arch('linux/arm64')
            ->build();

        $validator->validate($def);
        $this->addToAssertionCount(1);
    }

    public function test_target_without_arch_constraint_accepts_any(): void
    {
        $validator = $this->createValidator(useSingleNode: true);
        $def = DeploymentDefinition::create()
            ->host('single-node')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->arch('linux/amd64')
            ->build();

        $validator->validate($def);
        $this->addToAssertionCount(1);
    }

    private function createValidator(bool $useSingleNode = false): DeploymentDefinitionValidator
    {
        $targetMap = ['fake-target' => new FakeDeployTarget()];
        if ($useSingleNode) {
            $targetMap['single-node'] = new SingleNodeTarget();
        }

        $strategies = new DeployStrategyRegistry();
        $strategies->register(new BlueGreenStrategy());
        $strategies->register(new RollingStrategy());

        return new DeploymentDefinitionValidator(
            new DeployTargetRegistry(new InMemoryServiceLocator($targetMap)),
            new ContainerRegistryRegistry(new InMemoryServiceLocator(['fake-registry' => new FakeContainerRegistry()])),
            new CredentialProviderRegistry(new InMemoryServiceLocator(['fake-credential' => new FakeCredentialProvider()])),
            $strategies,
        );
    }
}
