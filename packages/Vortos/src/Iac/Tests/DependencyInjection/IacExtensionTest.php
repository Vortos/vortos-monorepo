<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Command\IacExportCommand;
use Vortos\Iac\DependencyInjection\IacExtension;
use Vortos\Iac\DependencyInjection\IacPackage;

final class IacExtensionTest extends TestCase
{
    private function boot(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $package = new IacPackage();
        $package->build($container);

        $extension = $package->getContainerExtension();
        $container->registerExtension($extension);
        $extension->load([], $container);

        return $container;
    }

    public function test_full_service_graph_compiles_and_command_is_instantiable(): void
    {
        $container = $this->boot();
        $container->compile();

        $this->assertInstanceOf(IacExportCommand::class, $container->get(IacExportCommand::class));
        $this->assertSame([], $container->getParameter('vortos.iac.exports'));
    }

    public function test_command_is_tagged_for_console(): void
    {
        $container = $this->boot();

        $this->assertArrayHasKey(
            IacExportCommand::class,
            $container->findTaggedServiceIds('console.command'),
        );
    }

    public function test_infra_config_attribute_is_autoconfigured(): void
    {
        $container = $this->boot();

        $this->assertArrayHasKey(
            \Vortos\Iac\Attribute\InfraConfig::class,
            $container->getAutoconfiguredAttributes(),
        );
    }
}
