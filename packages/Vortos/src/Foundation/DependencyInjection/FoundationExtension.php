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
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Health\Http\HealthController;
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
    }
}
