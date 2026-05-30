<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', '../src/')
        ->exclude([
            '../src/*/Domain/',
            '../src/*/Presentation/View/',
            '../src/Entity/',
        ]);

    $services->alias(ContainerInterface::class, 'service_container');
};
