<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\DependencyInjection\Compiler;

use Fortizan\Tekton\Messaging\Attribute\RegisterConsumer;
use Fortizan\Tekton\Messaging\Attribute\RegisterProducer;
use Fortizan\Tekton\Messaging\Attribute\RegisterTransport;
use Fortizan\Tekton\Messaging\Contract\DomainEventInterface;
use Fortizan\Tekton\Messaging\Definition\Consumer\AbstractConsumerDefinition;
use Fortizan\Tekton\Messaging\Definition\Producer\AbstractProducerDefinition;
use Fortizan\Tekton\Messaging\Definition\Transport\AbstractTransportDefinition;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MessagingConfigCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $transportDefinitions = [];
        $producerDefinitions = [];
        $consumerDefinitions = [];

        $taggedServices = $container->findTaggedServiceIds('tekton.messaging_config');
        $configServiceIds = array_keys($taggedServices);

        foreach ($configServiceIds as $serviceId) {
            $containerDefinition = $container->getDefinition($serviceId);
            $className = $containerDefinition->getClass();
            $reflClass = new ReflectionClass($className);

            $constructor = $reflClass->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new LogicException(
                    "MessagingConfig class '{$className}' must have no constructor dependencies. It is instantiated by the compiler pass via reflection."
                );
            }

            $configInstance = $reflClass->newInstance();

            $methods = $reflClass->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $this->processMethod(
                    $method,
                    $configInstance,
                    $transportDefinitions,
                    $producerDefinitions,
                    $consumerDefinitions
                );
            }
        }

        $this->validateReferences($transportDefinitions, $producerDefinitions, $consumerDefinitions);

        $eventProducerMap = [];
        foreach ($producerDefinitions as $producerName => $producer) {
            foreach ($producer->getPublishedEvents() as $eventClass) {
                if (isset($eventProducerMap[$eventClass])) {
                    throw new \LogicException(
                        "Event '{$eventClass}' is mapped to multiple producers: '{$eventProducerMap[$eventClass]}' and '{$producerName}'. Each event class can only be produced by one producer."
                    );
                }
                $eventProducerMap[$eventClass] = $producerName;
            }
        }
        
        $container->setParameter('tekton.transports', $transportDefinitions);
        $container->setParameter('tekton.producers', $producerDefinitions);
        $container->setParameter('tekton.consumers', $consumerDefinitions);
        $container->setParameter('tekton.event_producer_map', $eventProducerMap);
    }

    private function processMethod(ReflectionMethod $method, object $configInstance, array &$transportDefinitions, array &$producerDefinitions, array &$consumerDefinitions): void
    {
        $transportAttrs = $method->getAttributes(RegisterTransport::class);

        if (!empty($transportAttrs)) {
            $result = $method->invoke($configInstance);

            if (!$result instanceof AbstractTransportDefinition) {
                throw new LogicException(
                    "Method '{$method->getName()}' marked with #[RegisterTransport] must return AbstractTransportDefinition"
                );
            }

            $transportName = $result->getName();

            if (isset($transportDefinitions[$transportName])) {
                throw new LogicException(
                    "Duplicate transport name '{$transportName}'"
                );
            }

            $transportDefinitions[$transportName] = $result;
        }

        $producerAttrs = $method->getAttributes(RegisterProducer::class);

        if (!empty($producerAttrs)) {
            $result = $method->invoke($configInstance);

            if (!$result instanceof AbstractProducerDefinition) {
                throw new LogicException(
                    "Method '{$method->getName()}' marked with #[RegisterProducer] must return AbstractProducerDefinition"
                );
            }

            $transportName = $result->getName();

            if (isset($producerDefinitions[$transportName])) {
                throw new LogicException(
                    "Duplicate producer name '{$transportName}'"
                );
            }

            $producerDefinitions[$transportName] = $result;
        }

        $consumerAttrs = $method->getAttributes(RegisterConsumer::class);

        if (!empty($consumerAttrs)) {
            $result = $method->invoke($configInstance);

            if (!$result instanceof AbstractConsumerDefinition) {
                throw new LogicException(
                    "Method '{$method->getName()}' marked with #[RegisterConsumer] must return AbstractConsumerDefinition"
                );
            }

            $transportName = $result->getName();

            if (isset($consumerDefinitions[$transportName])) {
                throw new LogicException(
                    "Duplicate consumer name '{$transportName}'"
                );
            }

            $consumerDefinitions[$transportName] = $result;
        }
    }

    private function validateReferences(array $transportDefinitions, array $producerDefinitions, array $consumerDefinitions): void
    {
        foreach ($producerDefinitions as $producerName => $producer) {
            $producerConfig = $producer->toArray();

            $referencedTransport = $producerConfig['transport'] ?? '';

            if (!empty($referencedTransport) && !isset($transportDefinitions[$referencedTransport])) {
                throw new LogicException(
                    "Producer '{$producerName}' references transport '{$referencedTransport}' which is not registered"
                );
            }
        }

        foreach ($consumerDefinitions as $consumerName => $consumer) {
            $consumerConfig = $consumer->toArray();
            $referencedTransport = $consumerConfig['transport'] ?? $consumerName;

            if (!isset($transportDefinitions[$referencedTransport])) {
                throw new LogicException(
                    "Consumer '{$consumerName}' references transport '{$consumerName}' which is not registered"
                );
            }
        }

        foreach ($producerDefinitions as $producerName => $producer) {
            foreach ($producer->getPublishedEvents() as $eventClass) {
                if (!class_exists($eventClass)) {
                    throw new \LogicException(
                        "Producer '{$producerName}' declares event '{$eventClass}' in publishes() but the class does not exist."
                    );
                }
                if (!is_a($eventClass, DomainEventInterface::class, true)) {
                    throw new \LogicException(
                        "Producer '{$producerName}' declares '{$eventClass}' in publishes() but it does not implement DomainEventInterface."
                    );
                }
            }
        }
    }
}
