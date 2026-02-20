<?php

use Fortizan\Tekton\Messenger\Transport\Kafka\Middleware\TopicResolverMiddleware;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator) {

        $services = $configurator->services();

        // default bus
        $services->alias(MessageBusInterface::class, 'tekton.bus.event');
        $services->alias(MessageBusInterface::class . ' $messageBus', 'tekton.bus.event');
        $services->set('tekton.bus.event', MessageBus::class)
                ->args([
                        [
                                service(TopicResolverMiddleware::class),
                                new Reference('tekton.bus.event.send_middleware'),
                                new Reference('tekton.bus.event.handle_middleware')
                        ]
                ])->tag('messenger.bus');

        $services->set(TopicResolverMiddleware::class)
                ->args([
                        []
                ]);

        $services->set('tekton.bus.event.send_middleware', SendMessageMiddleware::class)
                ->args([
                        service('messenger.sender_locator'),
                        service(EventDispatcher::class)
                ]);

        $services->set('tekton.bus.event.handle_middleware', HandleMessageMiddleware::class)
                ->args([new Reference('tekton.bus.event.locator')]);

        $services->set('tekton.bus.event.locator', HandlersLocator::class)
                ->args([[]]);


        //  command bus
        $services->alias(MessageBusInterface::class . ' $commandBus', 'tekton.bus.command');
        $services->set('tekton.bus.command', MessageBus::class)
                ->args([[new Reference('tekton.bus.command.middleware')]])
                ->tag('messenger.bus');

        $services->set('tekton.bus.command.middleware', HandleMessageMiddleware::class)
                ->args([new Reference('tekton.bus.command.locator')]);

        $services->set('tekton.bus.command.locator', HandlersLocator::class)
                ->args([[]]);


        //  query bus
        $services->alias(MessageBusInterface::class . ' $queryBus', 'tekton.bus.query');
        $services->set('tekton.bus.query', MessageBus::class)
                ->args([[new Reference('tekton.bus.query.middleware')]])
                ->tag('messenger.bus');

        $services->set('tekton.bus.query.middleware', HandleMessageMiddleware::class)
                ->args([new Reference('tekton.bus.query.locator')]);

        $services->set('tekton.bus.query.locator', HandlersLocator::class)
                ->args([[]]);
};
