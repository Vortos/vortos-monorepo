<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use Vortos\Messaging\Attribute\AsEventHandler;
use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\Timestamp;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Domain\Event\DomainEventInterface;

final class HandlerDiscoveryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.handlers')) {
            $container->setParameter('vortos.handlers', []);
        }

        $taggedHandlers = $container->findTaggedServiceIds('vortos.event_handler');
      
        foreach ($taggedHandlers as $serviceId => $tags) {
            $containerDefinition = $container->getDefinition($serviceId);
            $className = $containerDefinition->getClass();

            $reflClass = new ReflectionClass($className);

            $this->processHandlerClass($container, $serviceId, $reflClass);
        }

        // $handlerServices = [];
        // foreach ($taggedHandlers as $serviceId => $tags) {
        //     $handlerServices[$serviceId] = new Reference($serviceId);
        // }

        // $container->getDefinition('vortos.handler_locator')
        //     ->setArguments([$handlerServices]);
    }

    private function processHandlerClass(ContainerBuilder $container, string $serviceId, ReflectionClass $reflClass): void
    {
        $classAttrs = $reflClass->getAttributes(AsEventHandler::class);

        if (!empty($classAttrs)) {
            $attribute = $classAttrs[0]->newInstance();

            if (!$reflClass->hasMethod('__invoke')) {
                throw new LogicException(
                    "Class '{$reflClass->getName()}' has #[AsEventHandler] but no __invoke method"
                );
            }

            $invokeMethod = $reflClass->getMethod('__invoke');

            $this->buildAndStoreDescriptor($container, $serviceId, $invokeMethod, $attribute);
        }

        $methods = $reflClass->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {

            if (!empty($classAttrs) && $method->getName() === '__invoke') {
                continue;
            }

            $methodAttrs = $method->getAttributes(AsEventHandler::class);

            foreach ($methodAttrs as $attrRefl) {
                $attribute = $attrRefl->newInstance();

                $this->buildAndStoreDescriptor($container, $serviceId, $method, $attribute);
            }
        }
    }

    private function buildAndStoreDescriptor(
        ContainerBuilder $container,
        string $serviceId,
        ReflectionMethod $method,
        AsEventHandler $attribute
    ): void {
        $parameters = $this->resolveHandlerParameters($method);

        if (empty($parameters) || $parameters[0]['type'] !== 'event') {
            throw new LogicException(
                "Handler '{$method->getDeclaringClass()->getName()}::{$method->getName()}' has no parameter implementing DomainEventInterface"
            );
        }

        $eventClass = $parameters[0]['eventClass'];

        $descriptor = [
            'handlerId' => $attribute->handlerId,
            'serviceId' => $serviceId,
            'method' => $method->getName(),
            'priority' => $attribute->priority,
            'idempotent' => $attribute->idempotent,
            'version' => $attribute->version,
            'eventClass' =>  $eventClass,
            'parameters' => $parameters
        ];

        $handlers = $container->getParameter('vortos.handlers');
        $handlers[$attribute->consumer][$eventClass][] = $descriptor;

        $container->setParameter('vortos.handlers', $handlers);
    }

    private function resolveHandlerParameters(ReflectionMethod $method): array
    {
        $parameters = [];
        foreach ($method->getParameters() as $param) {

            $headerAttrClasses = [
                MessageId::class,
                CorrelationId::class,
                Timestamp::class,
            ];

            $headerFound = null;
            foreach ($headerAttrClasses as $attrClass) {
                if (!empty($param->getAttributes($attrClass))) {
                    $headerFound = $attrClass;
                    break;
                }
            }

            if ($headerFound !== null) {
                $paramType = $param->getType()?->getName() ?? 'string';
                $parameters[] = [
                    'type'      => 'header',
                    'attribute' => $headerFound,
                    'paramType' => $paramType,
                ];
            } else {



                $type = $param->getType();

                if (!$type instanceof ReflectionNamedType) {
                    continue;
                }

                if ($type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();

                if (!class_exists($typeName)) {
                    throw new LogicException(
                        "Parameter type '{$typeName}' in handler '{$method->getDeclaringClass()->getName()}::{$method->getName()}' does not exist"
                    );
                }

                $reflEventClass = new ReflectionClass($typeName);

                if (!$reflEventClass->implementsInterface(DomainEventInterface::class)) {
                    throw new LogicException(
                        "Parameter '{$typeName}' in handler '{$method->getDeclaringClass()->getName()}::{$method->getName()}' must implement DomainEventInterface"
                    );
                }

                $parameters[] = [
                    'type' => 'event',
                    'eventClass' => $typeName,
                ];
            }
        }

        return $parameters;
    }
}
