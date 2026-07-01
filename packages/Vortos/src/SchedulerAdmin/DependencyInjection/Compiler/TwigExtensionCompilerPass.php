<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\SchedulerAdmin\Rendering\AdminTwigExtension;

final class TwigExtensionCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('vortos.scheduler_admin.twig')) {
            return;
        }

        $twigDef = $container->getDefinition('vortos.scheduler_admin.twig');
        $twigDef->addMethodCall('addExtension', [new Reference(AdminTwigExtension::class)]);
    }
}
