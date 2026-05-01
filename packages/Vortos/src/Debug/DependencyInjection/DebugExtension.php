<?php

declare(strict_types=1);

namespace Vortos\Debug\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Vortos\Debug\Command\DebugContainerCommand;
use Vortos\Debug\Command\DebugRoutesCommand;

final class DebugExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_debug';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register(DebugRoutesCommand::class, DebugRoutesCommand::class)
            ->setArgument('$routes', [])
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(DebugContainerCommand::class, DebugContainerCommand::class)
            ->setArgument('$services', [])
            ->setArgument('$aliases', [])
            ->setPublic(true)
            ->addTag('console.command');
    }
}
