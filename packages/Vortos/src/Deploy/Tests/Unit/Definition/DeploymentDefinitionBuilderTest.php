<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Release\Manifest\Arch;

final class DeploymentDefinitionBuilderTest extends TestCase
{
    public function test_build_with_defaults(): void
    {
        $def = DeploymentDefinition::create()->build();

        self::assertSame('ssh-compose', $def->host);
        self::assertSame('dockerhub', $def->registry);
        self::assertSame('github', $def->ci);
        self::assertSame('env', $def->secrets);
        self::assertSame('grafana', $def->monitoring);
        self::assertSame([], $def->notifiers);
        self::assertSame('ssh-key', $def->credential);
        self::assertSame(DeployStrategy::BlueGreen, $def->strategy);
        self::assertSame(Arch::Arm64, $def->arch);
        self::assertTrue($def->autoRollback);
    }

    public function test_fluent_builder_is_immutable(): void
    {
        $b1 = DeploymentDefinition::create();
        $b2 = $b1->host('k8s');

        $d1 = $b1->build();
        $d2 = $b2->build();

        self::assertSame('ssh-compose', $d1->host);
        self::assertSame('k8s', $d2->host);
    }

    public function test_build_with_all_overrides(): void
    {
        $def = DeploymentDefinition::create()
            ->host('k8s')
            ->registry('ghcr')
            ->ci('gitlab')
            ->secrets('vault')
            ->monitoring('datadog')
            ->notifiers(['slack', 'telegram'])
            ->credential('ssh-ca-oidc')
            ->strategy('rolling')
            ->arch('linux/amd64')
            ->autoRollback(false)
            ->build();

        self::assertSame('k8s', $def->host);
        self::assertSame('ghcr', $def->registry);
        self::assertSame('gitlab', $def->ci);
        self::assertSame('vault', $def->secrets);
        self::assertSame('datadog', $def->monitoring);
        self::assertSame(['slack', 'telegram'], $def->notifiers);
        self::assertSame('ssh-ca-oidc', $def->credential);
        self::assertSame(DeployStrategy::Rolling, $def->strategy);
        self::assertSame(Arch::Amd64, $def->arch);
        self::assertFalse($def->autoRollback);
    }

    public function test_unknown_strategy_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown strategy "nonexistent"');

        DeploymentDefinition::create()->strategy('nonexistent')->build();
    }

    public function test_unknown_arch_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown arch "windows/x86"');

        DeploymentDefinition::create()->arch('windows/x86')->build();
    }

    public function test_empty_host_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"host" must not be empty');

        DeploymentDefinition::create()->host('')->build();
    }

    public function test_empty_registry_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"registry" must not be empty');

        DeploymentDefinition::create()->registry('')->build();
    }

    public function test_empty_ci_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"ci" must not be empty');

        DeploymentDefinition::create()->ci('')->build();
    }

    public function test_empty_secrets_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"secrets" must not be empty');

        DeploymentDefinition::create()->secrets('')->build();
    }

    public function test_empty_credential_rejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"credential" must not be empty');

        DeploymentDefinition::create()->credential('')->build();
    }

    public function test_duplicate_notifiers_deduplicated(): void
    {
        $def = DeploymentDefinition::create()
            ->notifiers(['slack', 'slack', 'telegram'])
            ->build();

        self::assertSame(['slack', 'telegram'], $def->notifiers);
    }

    public function test_definition_hash_is_stable(): void
    {
        $d1 = DeploymentDefinition::create()->build();
        $d2 = DeploymentDefinition::create()->build();

        self::assertSame($d1->definitionHash, $d2->definitionHash);
    }

    public function test_definition_hash_changes_with_config(): void
    {
        $d1 = DeploymentDefinition::create()->build();
        $d2 = DeploymentDefinition::create()->host('k8s')->build();

        self::assertNotSame($d1->definitionHash, $d2->definitionHash);
    }

    public function test_to_array_is_sorted(): void
    {
        $def = DeploymentDefinition::create()->build();
        $arr = $def->toArray();
        $keys = array_keys($arr);
        $sorted = $keys;
        sort($sorted);

        self::assertSame($sorted, $keys);
    }

    public function test_for_environment_applies_override(): void
    {
        $builder = DeploymentDefinition::create()
            ->forEnvironment('prod', fn ($b) => $b->strategy('canary'));

        $base = $builder->build();
        $prod = $builder->buildForEnvironment('prod');
        $dev = $builder->buildForEnvironment('dev');

        self::assertSame(DeployStrategy::BlueGreen, $base->strategy);
        self::assertSame(DeployStrategy::Canary, $prod->strategy);
        self::assertSame(DeployStrategy::BlueGreen, $dev->strategy);
    }

    public function test_static_build_factory(): void
    {
        $def = DeploymentDefinition::build(
            host: 'k8s',
            strategy: DeployStrategy::Rolling,
            arch: Arch::Amd64,
        );

        self::assertSame('k8s', $def->host);
        self::assertSame(DeployStrategy::Rolling, $def->strategy);
        self::assertSame(Arch::Amd64, $def->arch);
    }
}
