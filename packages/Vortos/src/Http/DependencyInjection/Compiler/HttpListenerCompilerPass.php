<?php

declare(strict_types=1);

namespace Vortos\Http\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\EventListener\RouterListener;

class HttpListenerCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container):void
    {
        if (!$container->getParameter('kernel.enable_routes')) {
            return;
        }
        
        if(!$container->hasDefinition(RouterListener::class)){
            return;
        }

        $routerListener = $container->getDefinition(RouterListener::class);
        
        $routerListener->setAutoconfigured(true);
        $routerListener->setAutowired(true);
        $routerListener->addTag('kernel.event_subscriber');
    }
}