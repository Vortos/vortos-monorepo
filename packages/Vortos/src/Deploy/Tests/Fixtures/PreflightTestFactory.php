<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Contract\ManualReadiness;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Definition\DeploymentDefinitionBuilder;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Definition\LayeredDefinitionResolver;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployPreflightStateBuilder;
use Vortos\Deploy\Preflight\PreflightContext;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Target\DeployTargetRegistry;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

/**
 * Shared builders for Block 12 (deploy doctor / runner) tests: registries wired to
 * fakes, a default definition, manifest, state, and context. Keeps each test focused
 * on the behaviour under test rather than on assembly.
 */
trait PreflightTestFactory
{
    /** @param array<string, object> $extra additional/overriding services keyed by driver key */
    protected function targetRegistry(array $extra = []): DeployTargetRegistry
    {
        return new DeployTargetRegistry(new InMemoryServiceLocator(
            $extra + ['fake-target' => new FakeDeployTarget()],
        ));
    }

    /** @param array<string, object> $extra */
    protected function registryRegistry(array $extra = []): ContainerRegistryRegistry
    {
        return new ContainerRegistryRegistry(new InMemoryServiceLocator(
            $extra + ['fake-registry' => new FakeContainerRegistry()],
        ));
    }

    /** @param array<string, object> $extra */
    protected function credentialRegistry(array $extra = []): CredentialProviderRegistry
    {
        return new CredentialProviderRegistry(new InMemoryServiceLocator(
            $extra + ['fake-credential' => new FakeCredentialProvider()],
        ));
    }

    protected function strategyRegistry(): DeployStrategyRegistry
    {
        $registry = new DeployStrategyRegistry();
        $registry->register(new BlueGreenStrategy());
        $registry->register(new RollingStrategy());
        $registry->register(new RecreateStrategy());
        $registry->register(new CanaryStrategy());

        return $registry;
    }

    protected function definition(
        string $host = 'fake-target',
        string $registry = 'fake-registry',
        string $credential = 'fake-credential',
        DeployStrategy $strategy = DeployStrategy::BlueGreen,
        Arch $arch = Arch::Arm64,
        bool $autoRollback = true,
    ): DeploymentDefinition {
        return DeploymentDefinition::build(
            host: $host,
            registry: $registry,
            credential: $credential,
            strategy: $strategy,
            arch: $arch,
            autoRollback: $autoRollback,
        );
    }

    /** @param list<string> $migrationIds */
    protected function manifest(
        array $migrationIds = ['m001'],
        Arch $arch = Arch::Arm64,
        string $env = 'production',
        string $buildId = 'build-1',
    ): BuildManifest {
        return new BuildManifest(
            buildId: $buildId,
            gitSha: 'abc1234',
            imageDigest: 'sha256:' . str_repeat('a', 64),
            targetArch: $arch,
            environment: $env,
            schemaFingerprint: new SchemaFingerprint($migrationIds),
            createdAt: new \DateTimeImmutable('2026-01-01'),
        );
    }

    /**
     * @param list<string> $appliedIds
     * @param list<string> $pendingContract
     */
    protected function state(
        array $appliedIds = ['m001'],
        array $pendingContract = [],
        ActiveColor $color = ActiveColor::Blue,
    ): CurrentDeployState {
        return new CurrentDeployState(
            activeColor: $color,
            currentDigest: 'sha256:' . str_repeat('a', 64),
            appliedFingerprint: new SchemaFingerprint($appliedIds),
            pendingContractMigrations: $pendingContract,
        );
    }

    protected function resolver(
        string $host = 'fake-target',
        string $registry = 'fake-registry',
        string $credential = 'fake-credential',
        DeployStrategy $strategy = DeployStrategy::BlueGreen,
        Arch $arch = Arch::Arm64,
        bool $autoRollback = true,
    ): LayeredDefinitionResolver {
        $builder = (new DeploymentDefinitionBuilder())
            ->host($host)
            ->registry($registry)
            ->credential($credential)
            ->strategy($strategy->value)
            ->arch($arch->value)
            ->autoRollback($autoRollback);

        return new LayeredDefinitionResolver($builder);
    }

    /** @param list<string> $appliedIds */
    protected function stateBuilder(array $appliedIds = ['m001']): DeployPreflightStateBuilder
    {
        $stateStore = new FakeDeployStateStore();

        return new DeployPreflightStateBuilder(
            new FakeAppliedMigrationSetReader(new SchemaFingerprint($appliedIds)),
            new FakeMigrationPhaseReader(),
            new ManualReadiness(),
            $stateStore,
            $stateStore,
        );
    }

    protected function context(
        ?DeploymentDefinition $definition = null,
        ?BuildManifest $manifest = null,
        ?CurrentDeployState $state = null,
        string $env = 'production',
    ): PreflightContext {
        return new PreflightContext(
            $definition ?? $this->definition(),
            $manifest ?? $this->manifest(),
            $state ?? $this->state(),
            new EnvironmentName($env),
        );
    }
}
