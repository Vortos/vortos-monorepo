<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use Vortos\Foundation\Config\Env;
use Vortos\Messaging\Attribute\RegisterConsumer;
use Vortos\Messaging\Attribute\RegisterProducer;
use Vortos\Messaging\Attribute\RegisterTransport;
use Vortos\Messaging\Definition\Consumer\AbstractConsumerDefinition;
use Vortos\Messaging\Definition\Producer\AbstractProducerDefinition;
use Vortos\Messaging\Definition\Transport\AbstractTransportDefinition;
use Vortos\Messaging\Contracts\ContractLock;
use Vortos\Messaging\Definition\WireNaming;
use Vortos\Messaging\Upcasting\UpcasterInterface;
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
        
        $taggedServices = $container->findTaggedServiceIds('vortos.messaging_config');
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
        $eventWireMap     = [];  // class → ['name' => logical name, 'version' => int]   (producer side)
        $wireEventMap     = [];  // logical name → local contract class                  (consumer side)
        $nameOwners       = [];  // logical name → declaring class, for global uniqueness

        foreach ($producerDefinitions as $producerName => $producer) {
            foreach ($producer->getPublishedContracts() as $eventClass => $contract) {
                if (isset($eventProducerMap[$eventClass])) {
                    throw new \LogicException(
                        "Event '{$eventClass}' is mapped to multiple producers: '{$eventProducerMap[$eventClass]}' and '{$producerName}'. Each event class can only be produced by one producer."
                    );
                }
                $eventProducerMap[$eventClass] = $producerName;

                if ($contract['as'] !== null && !WireNaming::isValidName($contract['as'])) {
                    throw new \LogicException(
                        "Producer '{$producerName}' declares invalid wire name '{$contract['as']}' for '{$eventClass}'. Use dot-separated lowercase segments, e.g. 'registration.entry_approved'."
                    );
                }

                $wireName = $contract['as'] ?? WireNaming::derive($eventClass);

                if (isset($nameOwners[$wireName]) && $nameOwners[$wireName] !== $eventClass) {
                    throw new \LogicException(
                        "Wire event name '{$wireName}' is declared by both '{$nameOwners[$wireName]}' and '{$eventClass}'. Wire names must be globally unique — pin one with publish(..., as: '...')."
                    );
                }

                $nameOwners[$wireName] = $eventClass;
                $eventWireMap[$eventClass] = ['name' => $wireName, 'version' => $contract['version']];
                // Same-process consumption default: the producer's class doubles as
                // the local contract unless a consumer declares its own below.
                $wireEventMap[$wireName] = $eventClass;
            }
        }

        $upcasterMap = [];  // wire name → [fromVersion => upcaster class]

        foreach ($consumerDefinitions as $consumerName => $consumer) {
            foreach ($consumer->getContracts() as $wireName => $localClass) {
                if (!class_exists($localClass)) {
                    throw new \LogicException(
                        "Consumer '{$consumerName}' maps wire event '{$wireName}' to '{$localClass}' but the class does not exist."
                    );
                }
                // Explicit handles() wins over the producer-derived default —
                // this is exactly how a module declares its OWN contract class.
                $wireEventMap[$wireName] = $localClass;
            }

            foreach ($consumer->getUpcasters() as $wireName => $steps) {
                foreach ($steps as $fromVersion => $upcasterClass) {
                    if (!class_exists($upcasterClass)) {
                        throw new \LogicException(
                            "Consumer '{$consumerName}' registers upcaster '{$upcasterClass}' for '{$wireName}' v{$fromVersion} but the class does not exist."
                        );
                    }
                    if (!is_subclass_of($upcasterClass, UpcasterInterface::class)) {
                        throw new \LogicException(
                            "Upcaster '{$upcasterClass}' for '{$wireName}' must implement " . UpcasterInterface::class . '.'
                        );
                    }
                    $ctor = (new ReflectionClass($upcasterClass))->getConstructor();
                    if ($ctor !== null && $ctor->getNumberOfRequiredParameters() > 0) {
                        throw new \LogicException(
                            "Upcaster '{$upcasterClass}' must have a dependency-free constructor — upcasters are pure array transforms instantiated by the runtime."
                        );
                    }
                    if (isset($upcasterMap[$wireName][$fromVersion]) && $upcasterMap[$wireName][$fromVersion] !== $upcasterClass) {
                        throw new \LogicException(
                            "Conflicting upcasters for '{$wireName}' v{$fromVersion}: '{$upcasterMap[$wireName][$fromVersion]}' and '{$upcasterClass}'."
                        );
                    }
                    $upcasterMap[$wireName][$fromVersion] = $upcasterClass;
                }
            }
        }

        $container->setParameter('vortos.transports', $this->resolveEnvReferences(array_map(fn($d) => $d->toArray(), $transportDefinitions), $container));
        $container->setParameter('vortos.producers', $this->resolveEnvReferences(array_map(fn($d) => $d->toArray(), $producerDefinitions), $container));
        $container->setParameter('vortos.consumers', $this->resolveEnvReferences(array_map(fn($d) => $d->toArray(), $consumerDefinitions), $container));
        $container->setParameter('vortos.event_producer_map', $eventProducerMap);
        $container->setParameter('vortos.event_wire_map', $eventWireMap);
        $container->setParameter('vortos.wire_event_map', $wireEventMap);
        $container->setParameter('vortos.upcaster_map', $upcasterMap);

        $this->checkContractLock($container, $eventWireMap);
    }

    /**
     * Compile-time contract drift check: if a contracts.lock exists, any
     * mismatch between it and the live publishes() declarations FAILS THE
     * BUILD. This is what makes convention-derived wire names safe — renaming
     * an event class or changing its payload without a version bump becomes a
     * compile error instead of silently breaking consumers and in-flight
     * messages.
     *
     * Intentional changes: set VORTOS_CONTRACTS_SKIP_CHECK=1, rebuild, run
     * `vortos:contracts:lock`, commit the lockfile.
     */
    private function checkContractLock(ContainerBuilder $container, array $eventWireMap): void
    {
        if (($_ENV['VORTOS_CONTRACTS_SKIP_CHECK'] ?? getenv('VORTOS_CONTRACTS_SKIP_CHECK') ?: '') !== '') {
            return;
        }

        if (!$container->hasParameter('kernel.project_dir')) {
            return;
        }

        $locked = ContractLock::load($container->getParameter('kernel.project_dir') . '/' . ContractLock::FILENAME);

        if ($locked === null) {
            return; // no lockfile yet — vortos:contracts:lock creates the baseline
        }

        $findings = ContractLock::diff($locked, ContractLock::compute($eventWireMap));

        if ($findings !== []) {
            throw new LogicException(
                "Wire contract drift detected (contracts.lock):\n  - " . implode("\n  - ", $findings)
                . "\nIf the change is intentional: VORTOS_CONTRACTS_SKIP_CHECK=1 to build, then run vortos:contracts:lock and commit."
            );
        }
    }

    /**
     * Recursively converts Env references in definition arrays to
     * '%env(...)%' placeholders, registering each declared default as the
     * container parameter the placeholder's `default:` processor reads.
     * The container resolves the placeholders at runtime, so typed settings
     * (partitions, replication factor) follow the exact same env path as
     * string DSNs — no $_ENV reads at compile time anywhere.
     */
    private function resolveEnvReferences(mixed $value, ContainerBuilder $container): mixed
    {
        if ($value instanceof Env) {
            if ($value->hasDefault() && !$container->hasParameter($value->defaultParameterName())) {
                $container->setParameter($value->defaultParameterName(), $value->default);
            }
            return $value->toPlaceholder();
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->resolveEnvReferences($v, $container), $value);
        }

        return $value;
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
                $refl = new ReflectionClass($eventClass);
                if (!$refl->isFinal()) {
                    throw new \LogicException(
                        "Producer '{$producerName}' declares '{$eventClass}' in publishes() but event classes must be final."
                    );
                }
            }
        }
    }
}
