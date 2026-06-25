<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Deploy\Credential\CredentialProviderRegistry;
use Vortos\Deploy\Definition\DeploymentDefinitionValidator;
use Vortos\Deploy\DependencyInjection\Compiler\CollectContainerRegistriesPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectCredentialProvidersPass;
use Vortos\Deploy\DependencyInjection\Compiler\CollectDeployTargetsPass;
use Vortos\Deploy\DependencyInjection\DeployExtension;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\PlanRenderer;
use Vortos\Deploy\Registry\ContainerRegistryRegistry;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;
use Vortos\Deploy\Target\DeployTargetRegistry;

final class DeployExtensionTest extends TestCase
{
    public function test_alias(): void
    {
        $ext = new DeployExtension();
        self::assertSame('vortos_deploy', $ext->getAlias());
    }

    public function test_registers_all_core_services(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        self::assertTrue($container->hasDefinition(DeployTargetRegistry::class));
        self::assertTrue($container->hasDefinition(ContainerRegistryRegistry::class));
        self::assertTrue($container->hasDefinition(CredentialProviderRegistry::class));
        self::assertTrue($container->hasDefinition(DeployStrategyRegistry::class));
        self::assertTrue($container->hasDefinition(DeployPlanner::class));
        self::assertTrue($container->hasDefinition(PlanRenderer::class));
        self::assertTrue($container->hasDefinition(DeploymentDefinitionValidator::class));
    }

    public function test_registers_locators(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        self::assertTrue($container->hasDefinition(CollectDeployTargetsPass::LOCATOR_ID));
        self::assertTrue($container->hasDefinition(CollectContainerRegistriesPass::LOCATOR_ID));
        self::assertTrue($container->hasDefinition(CollectCredentialProvidersPass::LOCATOR_ID));
    }

    public function test_registers_all_four_strategies(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        self::assertTrue($container->hasDefinition(BlueGreenStrategy::class));
        self::assertTrue($container->hasDefinition(RollingStrategy::class));
        self::assertTrue($container->hasDefinition(RecreateStrategy::class));
        self::assertTrue($container->hasDefinition(CanaryStrategy::class));
    }

    public function test_all_services_are_private(): void
    {
        $container = new ContainerBuilder();
        $ext = new DeployExtension();
        $ext->load([], $container);

        $publicServices = [
            DeployTargetRegistry::class,
            ContainerRegistryRegistry::class,
            CredentialProviderRegistry::class,
            DeployStrategyRegistry::class,
            DeployPlanner::class,
            PlanRenderer::class,
            DeploymentDefinitionValidator::class,
        ];

        foreach ($publicServices as $serviceId) {
            self::assertFalse(
                $container->getDefinition($serviceId)->isPublic(),
                $serviceId . ' should be private.',
            );
        }
    }
}
