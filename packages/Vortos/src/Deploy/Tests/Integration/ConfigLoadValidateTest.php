<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\DeploymentDefinitionValidator;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeCredentialProvider;
use Vortos\Deploy\Tests\Fixtures\FakeDeployTarget;
use Vortos\Deploy\Tests\Fixtures\InMemoryServiceLocator;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class ConfigLoadValidateTest extends TestCase
{
    public function test_end_to_end_load_validate_plan(): void
    {
        $definition = DeploymentDefinition::create()
            ->host('fake-target')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->build();

        $validator = $this->createValidator();
        $validator->validate($definition);

        $strategies = $this->createStrategies();
        $planner = new DeployPlanner($strategies);

        $context = new DeployContext(
            definition: $definition,
            desiredManifest: new BuildManifest(
                buildId: 'integration-build-1',
                gitSha: 'abc1234',
                imageRepository: 'ghcr.io/acme/app',
                imageDigest: 'sha256:' . str_repeat('a', 64),
                targetArch: Arch::Arm64,
                environment: 'prod',
                schemaFingerprint: new SchemaFingerprint(['m001']),
                createdAt: new \DateTimeImmutable(),
            ),
            currentState: CurrentDeployState::firstDeploy(),
        );

        $plan = $planner->plan($context);

        self::assertFalse($plan->isEmpty());
        self::assertTrue($plan->hasPhase(PhaseKind::StageColor));
        self::assertTrue($plan->hasPhase(PhaseKind::Promote));
        self::assertStringStartsWith('sha256:', $plan->planHash->toString());
    }

    public function test_bad_config_fails_closed(): void
    {
        $definition = DeploymentDefinition::create()
            ->host('nonexistent')
            ->registry('fake-registry')
            ->credential('fake-credential')
            ->build();

        $validator = $this->createValidator();

        $this->expectException(\Vortos\Deploy\Exception\UnknownDriverSelectionException::class);
        $validator->validate($definition);
    }

    public function test_stub_file_loads_and_validates(): void
    {
        $stubPath = dirname(__DIR__, 2) . '/Resources/stubs/deploy.php.stub';
        self::assertFileExists($stubPath);

        $definition = require $stubPath;
        self::assertInstanceOf(DeploymentDefinition::class, $definition);
    }

    private function createValidator(): DeploymentDefinitionValidator
    {
        return new DeploymentDefinitionValidator(
            new DeployTargetRegistry(new InMemoryServiceLocator(['fake-target' => new FakeDeployTarget()])),
            new ContainerRegistryRegistry(new InMemoryServiceLocator(['fake-registry' => new FakeContainerRegistry()])),
            new CredentialProviderRegistry(new InMemoryServiceLocator(['fake-credential' => new FakeCredentialProvider()])),
            $this->createStrategies(),
        );
    }

    private function createStrategies(): DeployStrategyRegistry
    {
        $registry = new DeployStrategyRegistry();
        $registry->register(new BlueGreenStrategy());
        $registry->register(new RollingStrategy());
        $registry->register(new RecreateStrategy());
        $registry->register(new CanaryStrategy());

        return $registry;
    }
}
