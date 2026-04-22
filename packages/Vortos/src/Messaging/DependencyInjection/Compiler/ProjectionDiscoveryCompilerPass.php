<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Cqrs\Attribute\AsProjectionHandler;
use Vortos\Domain\Event\DomainEventInterface;

/**
 * Discovers all classes tagged with #[AsProjectionHandler] and registers
 * their descriptors into vortos.handlers — the same map HandlerRegistry reads.
 *
 * Projection handlers are always idempotent. This pass enforces that at
 * compile time — no idempotent: false is possible for projections.
 *
 * Projection handlers are discovered separately from event handlers so
 * projection-specific behavior (replay mode, MongoDB retry, audit logging)
 * can be applied independently without affecting regular event handlers.
 *
 * Runs at priority 85 — after HandlerDiscovery (90) so both write into
 * the same vortos.handlers parameter without conflict.
 */
final class ProjectionDiscoveryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.handlers')) {
            $container->setParameter('vortos.handlers', []);
        }

        // Projection handlers are tagged 'vortos.projection_handler'
        // CqrsExtension must tag them with this — NOT vortos.event_handler
        $taggedHandlers = $container->findTaggedServiceIds('vortos.projection_handler');

        foreach ($taggedHandlers as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $className = $container->getDefinition($serviceId)->getClass();
                $reflClass = new ReflectionClass($className);

                // Get event class from __invoke() parameter type
                if (!$reflClass->hasMethod('__invoke')) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" must have an __invoke() method.',
                        $className,
                    ));
                }

                $params = $reflClass->getMethod('__invoke')->getParameters();

                if (empty($params)) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" __invoke() must have an event parameter.',
                        $className,
                    ));
                }

                $type = $params[0]->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" __invoke() first parameter must be a typed event class.',
                        $className,
                    ));
                }

                $eventClass = $type->getName();

                // Validate it implements DomainEventInterface
                $reflEvent = new ReflectionClass($eventClass);
                if (!$reflEvent->implementsInterface(DomainEventInterface::class)) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" parameter "%s" must implement DomainEventInterface.',
                        $className,
                        $eventClass,
                    ));
                }

                $consumer = $tag['consumer'] ?? null;
                $handlerId = $tag['handlerId'] ?? null;

                if ($consumer === null || $handlerId === null) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" tag is missing consumer or handlerId.',
                        $className,
                    ));
                }

                $descriptor = [
                    'handlerId'  => $handlerId,
                    'serviceId'  => $serviceId,
                    'method'     => '__invoke',
                    'priority'   => $tag['priority'] ?? 0,
                    'idempotent' => true,   // projections are ALWAYS idempotent — enforced here
                    'version'    => $tag['version'] ?? null,
                    'eventClass' => $eventClass,
                    'isProjection' => true, // flag for ConsumerRunner to apply projection behavior
                    'parameters' => [
                        ['type' => 'event', 'eventClass' => $eventClass],
                    ],
                ];

                $handlers = $container->getParameter('vortos.handlers');
                $handlers[$consumer][$eventClass][] = $descriptor;
                $container->setParameter('vortos.handlers', $handlers);
            }
        }

        // // Add projection handlers to the service locator
        // // They share the same locator as event handlers
        // if ($container->hasDefinition('vortos.handler_locator')) {
        //     $currentArgs = $container->getDefinition('vortos.handler_locator')->getArgument(0);
        //     foreach ($taggedHandlers as $serviceId => $_) {
        //         $currentArgs[$serviceId] = new \Symfony\Component\DependencyInjection\Reference($serviceId);
        //     }
        //     $container->getDefinition('vortos.handler_locator')->setArguments([$currentArgs]);
        // }
    }
}
