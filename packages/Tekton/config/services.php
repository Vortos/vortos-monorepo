<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Fortizan\Tekton\Controller\ErrorController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator) {

    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->bind('$projectRoot', '%kernel.project_dir%');

    $configurator->import('./packages/tekton.php');
    $configurator->import('./packages/messengerTransport.php');
    $configurator->import('./packages/messenger.php');
    $configurator->import('./packages/route.php');
    $configurator->import('./packages/event.php');
    $configurator->import('./packages/monolog.php');
    $configurator->import('./packages/doctrine.php');

    $services->load('Fortizan\\Tekton\\', '../src')
        ->exclude([
            '../src/Container/Container.php',
            '../src/Http/kernel.php',
            '../src/EventListener',
        ]);

    $services->get(ErrorController::class)
        ->arg('$debug', '%kernel.debug%')
        ->public();
    
    $services->alias(ContainerInterface::class,'service_container');
};
