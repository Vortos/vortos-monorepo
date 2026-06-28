<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Vortos\Foundation\Assets\AssetPublisher;
use Vortos\Foundation\Command\AssetsPublishCommand;
use Vortos\Foundation\Command\DebugBindingsCommand;
use Vortos\Foundation\Command\DoctorCommand;
use Vortos\Foundation\Command\HealthCommand;
use Vortos\Foundation\Doctor\DoctorRegistry;
use Vortos\Foundation\DependencyInjection\Attribute\AsDecorator;
use Vortos\Foundation\DependencyInjection\Attribute\DefaultImpl;
use Vortos\Foundation\DependencyInjection\Attribute\OverrideImpl;
use Vortos\Foundation\DependencyInjection\Attribute\ServiceProvider;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Reset\ServicesResetter;

final class FoundationExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_foundation';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(ServicesResetter::class, ServicesResetter::class)
            ->setArgument('$services', new Definition(ServiceLocator::class, [[]]))
            ->setArgument('$serviceIds', [])
            ->setPublic(true);

        $container->register(HealthRegistry::class, HealthRegistry::class)
            ->setArgument('$checks', [])
            ->setPublic(true);

        $container->register(HealthDetailPolicy::class, HealthDetailPolicy::class)
            ->setFactory([HealthDetailPolicy::class, 'fromEnvironment'])
            ->setPublic(false);

        $container->registerAttributeForAutoconfiguration(
            AsCommand::class,
            static function (ChildDefinition $definition, AsCommand $attribute): void {
                $definition->addTag('console.command');
            },
        );

        $container->registerAttributeForAutoconfiguration(
            DefaultImpl::class,
            static function (ChildDefinition $definition, DefaultImpl $attribute): void {
                $definition->addTag('vortos.default_impl');
            },
        );

        $container->registerAttributeForAutoconfiguration(
            OverrideImpl::class,
            static function (ChildDefinition $definition, OverrideImpl $attribute): void {
                $definition->addTag('vortos.override_impl');
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsDecorator::class,
            static function (ChildDefinition $definition, AsDecorator $attribute): void {
                $definition->addTag('vortos.decorator');
            },
        );

        $container->registerAttributeForAutoconfiguration(
            ServiceProvider::class,
            static function (ChildDefinition $definition, ServiceProvider $attr): void {
                $definition->addTag('vortos.service_provider');
            },
        );

        $container->register(DebugBindingsCommand::class, DebugBindingsCommand::class)
            ->setArgument('$bindings', '%vortos.default_impl.bindings%')
            ->addTag('console.command')
            ->setPublic(true);

        $container->register(HealthCommand::class, HealthCommand::class)
            ->setArgument('$registry', new Reference(HealthRegistry::class))
            ->addTag('console.command')
            ->setPublic(true);

        $container->register(DoctorRegistry::class, DoctorRegistry::class)
            ->setArgument('$checks', [])
            ->setPublic(true);

        $container->register(DoctorCommand::class, DoctorCommand::class)
            ->setArgument('$registry', new Reference(DoctorRegistry::class))
            ->addTag('console.command')
            ->setPublic(true);

        $container->register(AssetPublisher::class, AssetPublisher::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(AssetsPublishCommand::class, AssetsPublishCommand::class)
            ->setArgument('$publisher', new Reference(AssetPublisher::class))
            ->setArgument('$vendorDir', '%kernel.project_dir%/vendor')
            ->setArgument('$defaultPublicDir', '%kernel.project_dir%/public')
            ->addTag('console.command')
            ->setPublic(true);
    }
}
