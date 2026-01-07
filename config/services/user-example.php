<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator) {

    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();
};
