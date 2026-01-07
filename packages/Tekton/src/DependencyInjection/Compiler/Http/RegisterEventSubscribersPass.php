<?php

// namespace Fortizan\Tekton\DependencyInjection\Compiler\Http;

// use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
// use Symfony\Component\DependencyInjection\ContainerBuilder;
// use Symfony\Component\DependencyInjection\Reference;
// use Symfony\Component\EventDispatcher\EventDispatcher;

// class RegisterEventSubscribersPass implements CompilerPassInterface
// {
//     public function process(ContainerBuilder $container): void 
//     {
//         if(!$container->has(EventDispatcher::class)){
//             return;
//         }

//         $dispatcherDefinision = $container->getDefinition(EventDispatcher::class);

//         $eventSubscribers = $container->findTaggedServiceIds('kernel.event_subscriber');

//         foreach($eventSubscribers as $id => $tags){

//             $dispatcherDefinision->addMethodCall('addSubscriber', [new Reference($id)]);
//         }
//     }
// }



namespace Fortizan\Tekton\DependencyInjection\Compiler\Http;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RegisterEventSubscribersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(EventDispatcher::class)) {
            return;
        }

        $dispatcherDefinition = $container->getDefinition(EventDispatcher::class);
        $subscribers = $container->findTaggedServiceIds('kernel.event_subscriber');

        foreach ($subscribers as $id => $tags) {
            $def = $container->getDefinition($id);
            $class = $def->getClass();

            if (!class_exists($class) || !is_subclass_of($class, EventSubscriberInterface::class)) {
                continue;
            }

            $events = $class::getSubscribedEvents();

            foreach ($events as $eventName => $params) {
                if (is_string($params)) {
                    $this->addListener($dispatcherDefinition, $eventName, $id, $params);
                } elseif (is_string($params[0])) {
                    $this->addListener($dispatcherDefinition, $eventName, $id, $params[0], $params[1] ?? 0);
                } else {
                    foreach ($params as $listener) {
                        $this->addListener($dispatcherDefinition, $eventName, $id, $listener[0], $listener[1] ?? 0);
                    }
                }
            }
        }
    }

    private function addListener($dispatcherDef, string $event, string $serviceId, string $method, int $priority = 0): void
    {
        $dispatcherDef->addMethodCall('addListener', [
            $event,
            [new Reference($serviceId), $method],
            $priority
        ]);
    }
}