<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ConsoleCommandPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(Application::class)) {
            return;
        }

        $applicationDefinition = $container->findDefinition(Application::class);
        
        $taggedServices = $container->findTaggedServiceIds('console.command');

        foreach ($taggedServices as $id => $tags) {
            $applicationDefinition->addMethodCall('addCommand', [new Reference($id)]);
        }
    }
}
