<?php

use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\IntrospectionProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

return static function (ContainerConfigurator $configurator) {

    $services = $configurator->services();

    $services->set('monolog.formatter.json', JsonFormatter::class);
    $services->set('monolog.formatter.line', LineFormatter::class);

    $services->set('monolog.processor.introspection', IntrospectionProcessor::class);

    $services->set('monolog.handler.main', StreamHandler::class);

    $services->set('monolog.logger', Logger::class)
        ->args(['app'])
        ->call('pushHandler', [new Reference('monolog.handler.main')])
        ->call('pushProcessor', [new Reference('monolog.processor.introspection')]);

    $services->alias(LoggerInterface::class, 'monolog.logger');
};
