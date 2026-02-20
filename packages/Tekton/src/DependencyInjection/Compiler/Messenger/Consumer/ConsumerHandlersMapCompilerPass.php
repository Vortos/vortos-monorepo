<?php

namespace Fortizan\Tekton\DependencyInjection\Compiler\Messenger\Consumer;

use Fortizan\Tekton\Bus\Event\Attribute\AsEvent;
use Fortizan\Tekton\Messenger\Consumer;
use Reflection;
use ReflectionClass;
use ReflectionMethod;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ConsumerHandlersMapCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Consumer::class)) {
            return;
        }

        $handlerLocator = $container->getDefinition(Consumer::class);

        $handlerIds = $container->findTaggedServiceIds('tekton.event.handler');
        // dd($handlerIds);
        $handlersMap = [];
        foreach ($handlerIds as $id => $tags) {

            $this->validateHandlers($id, $tags);

            foreach ($tags as $tagAttributes) {

                $method = $tagAttributes['method']?? '__invoke';
                $groupId = $tagAttributes['group'];
                $channel = $tagAttributes['channel'];
                $priority = $tagAttributes['priority'] ?? 0;


                if ($groupId === '' || $groupId === null) {
                    throw new RuntimeException(
                        sprintf("Invalid group id defined for handler class '%s'", $id)
                    );
                }

                $def = $container->getDefinition($id);
                $handlerClass = $def->getClass();

                if (!$handlerClass) {
                    continue;
                }

                $eventClasses = $this->getEventClassFromHandlerMethod(
                    handlerClass: $handlerClass,
                    handlerMethod: $method
                );

                $options = [
                    'method' => $method,
                    'channel' => $channel
                ];

                foreach ($eventClasses as $eventClass) {
                    $handlersMap[$groupId][$eventClass][$priority][$id][] = $options;
                }
            }
        }

        $sortedHandlersMap = [];
        foreach ($handlersMap as $groupId => $groupData) {
            foreach ($groupData as $eventClass => $eventData) {
                krsort($eventData);

                $flatList = [];
                foreach ($eventData as $priority => $handlerData) {
                    foreach ($handlerData as $id => $options) {
                        $flatList[$id][] = $options;
                    }
                }

                $sortedHandlersMap[$groupId][$eventClass] = $flatList;
            }
        }

        // dd($sortedHandlersMap);
        $handlerLocator->setArgument('$globalHandlerMap', $sortedHandlersMap);
    }

    private function validateHandlers(string $handlerId, array $tags): void
    {
        $hasClassLevelAttribute = false;
        foreach ($tags as $tagAttributes) {
            if ($tagAttributes['method'] === null) {
                $hasClassLevelAttribute = true;
            } else {
                $methodLevelAttributes[] = $tagAttributes['method'];
            }
        }

        // Cannot have both class-level AND method-level attributes
        if ($hasClassLevelAttribute && !empty($methodLevelAttributes)) {
            throw new RuntimeException(sprintf(
                "Event Handler '%s' has #[AsEventHandler] on both class and methods [%s]. " .
                    "Use the attribute on either the class OR specific methods, not both.",
                $handlerId,
                implode(', ', $methodLevelAttributes)
            ));
        }
    }

    /**
     * @return string[]
     */
    private function getEventClassFromHandlerMethod(string $handlerClass, string $handlerMethod): array
    {
        $reflectionMethod = new ReflectionMethod($handlerClass, $handlerMethod);
        $reflectionParameters = $reflectionMethod->getParameters();

        if (empty($reflectionParameters)) {
            throw new RuntimeException(sprintf(
                "Event handler method '%s::%s' must have at least one parameter (the event)",
                $handlerClass,
                $handlerMethod
            ));
        }

        $eventParameter = $reflectionParameters[0];
        $parameterType = $eventParameter->getType();

        if ($parameterType instanceof ReflectionUnionType) {
            $eventClasses = $this->findUnionEventClasses($parameterType, $handlerClass, $handlerMethod);
            return $eventClasses;
        }

        if (!$parameterType || $parameterType->isBuiltin()) {
            throw new RuntimeException(sprintf(
                "Event handler method '%s::%s' first parameter must be an event class or interface",
                $handlerClass,
                $handlerMethod
            ));
        }

        $parameterClassName = $parameterType->getName();

        if (interface_exists($parameterClassName)) {
            $eventClasses = $this->findAllEventImplementations($parameterClassName);
            return $eventClasses;
        }


        $parameterClassReflection = new ReflectionClass($parameterClassName);
        $eventAttributes = $parameterClassReflection->getAttributes(AsEvent::class);

        if (empty($eventAttributes)) {
            throw new RuntimeException(sprintf(
                "Parameter '%s' in event handler '%s::%s' must be marked with #[AsEvent] attribute",
                $parameterClassName,
                $handlerClass,
                $handlerMethod
            ));
        }

        return [$parameterClassName];
    }

    private function findUnionEventClasses(ReflectionUnionType $reflectionUnion, string $handlerClass, string $handlerMethod): array
    {
        $types = $reflectionUnion->getTypes();

        $eventClasses = [];
        foreach ($types as $type) {
            if ($type->isBuiltin()) {
                throw new RuntimeException(sprintf(
                    "Union type in event handler '%s::%s' cannot contain builtin type '%s'. " .
                        "All types must be event classes marked with #[AsEvent].",
                    $handlerClass,
                    $handlerMethod,
                    $type->getName()
                ));
            }

            $typeName = $type->getName();

            $typeReflection = new ReflectionClass($typeName);
            if (empty($typeReflection->getAttributes(AsEvent::class))) {
                throw new RuntimeException(sprintf(
                    "Union type member '%s' in event handler '%s::%s' must be marked with #[AsEvent]",
                    $typeName,
                    $handlerClass,
                    $handlerMethod
                ));
            }

            $eventClasses[] = $typeName;
        }

        return $eventClasses;
    }

    private function findAllEventImplementations(string $interfaceName): array
    {
        $implementations = [];
        foreach (get_declared_classes() as $className) {
            if (is_subclass_of($className, $interfaceName)) {
                $implementations[] = $className;
            }
        }

        return $implementations;
    }
}
