<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Health\Http\HealthController;

final class FoundationExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_foundation';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(HealthRegistry::class, HealthRegistry::class)
            ->setArgument('$checks', [])
            ->setPublic(true);

        $container->register(HealthController::class, HealthController::class)
            ->setArgument('$registry', new Reference(HealthRegistry::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);
    }
}
