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
use Vortos\Foundation\Command\DebugBindingsCommand;
use Vortos\Foundation\Command\DoctorCommand;
use Vortos\Foundation\Command\HealthCommand;
use Vortos\Foundation\Doctor\DoctorRegistry;
use Vortos\Foundation\DependencyInjection\Attribute\AsCompilerPass;
use Vortos\Foundation\DependencyInjection\Attribute\DefaultImpl;
use Vortos\Foundation\DependencyInjection\Attribute\ServiceProvider;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Http\Health\HealthController;
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

        $container->register(HealthController::class, HealthController::class)
            ->setArgument('$registry', new Reference(HealthRegistry::class))
            ->setArgument('$detailPolicy', new Reference(HealthDetailPolicy::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

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
            AsCompilerPass::class,
            static function (ChildDefinition $definition, AsCompilerPass $attr): void {
                $definition->addTag('vortos.compiler_pass', [
                    'type'     => $attr->type->value,
                    'priority' => $attr->priority,
                ]);
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
    }
}
