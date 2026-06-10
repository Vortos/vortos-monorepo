<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Attribute\AsEventHandler;
use Vortos\Messaging\Attribute\Header\CausationId;
use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\Header;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\TenantId;
use Vortos\Messaging\Attribute\Header\Timestamp;
use Vortos\Messaging\Attribute\Header\TraceId;
use Vortos\Messaging\Attribute\Header\UserId;

/**
 * Discovers all services tagged 'vortos.event_handler', inspects their
 * #[AsEventHandler] attributes, and registers handler descriptors into the
 * 'vortos.handlers' container parameter.
 *
 * Enforces compile-time safety rules on each discovered event class:
 *   F1 — event class must be final
 *   F2 — all properties must be public readonly constructor-promoted
 *   F3 — class must have no methods other than __construct
 *   F4 — after the event param, only EventEnvelope, Metadata, or
 *        header-attributed (#[MessageId], #[CorrelationId], #[Timestamp],
 *        #[Header]) params are allowed
 *   F5 — handler method return type must be : void
 *
 * Discovery: the first non-builtin, non-EventEnvelope, non-Metadata,
 * non-header-attributed parameter of the handler method IS the event class.
 * No marker interface or attribute on the event class is required.
 */
final class HandlerDiscoveryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.handlers')) {
            $container->setParameter('vortos.handlers', []);
        }

        $taggedHandlers = $container->findTaggedServiceIds('vortos.event_handler');

        foreach ($taggedHandlers as $serviceId => $tags) {
            $className = $container->getDefinition($serviceId)->getClass();
            $this->processHandlerClass($container, $serviceId, new ReflectionClass($className));
        }
    }

    private function processHandlerClass(ContainerBuilder $container, string $serviceId, ReflectionClass $reflClass): void
    {
        $classAttrs = $reflClass->getAttributes(AsEventHandler::class);

        if (!empty($classAttrs)) {
            $attribute = $classAttrs[0]->newInstance();

            if (!$reflClass->hasMethod('__invoke')) {
                throw new LogicException(
                    "Class '{$reflClass->getName()}' has #[AsEventHandler] but no __invoke method."
                );
            }

            $this->buildAndStoreDescriptor($container, $serviceId, $reflClass->getMethod('__invoke'), $attribute);
        }

        foreach ($reflClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!empty($classAttrs) && $method->getName() === '__invoke') {
                continue;
            }

            foreach ($method->getAttributes(AsEventHandler::class) as $attrRefl) {
                $this->buildAndStoreDescriptor($container, $serviceId, $method, $attrRefl->newInstance());
            }
        }
    }

    private function buildAndStoreDescriptor(
        ContainerBuilder $container,
        string $serviceId,
        ReflectionMethod $method,
        AsEventHandler $attribute,
    ): void {
        $context    = "{$method->getDeclaringClass()->getName()}::{$method->getName()}";
        $parameters = $this->resolveHandlerParameters($method, $context);

        $eventParam = array_values(array_filter($parameters, fn($p) => $p['type'] === 'event'));

        if (empty($eventParam)) {
            throw new LogicException(
                "Handler '$context' has no event parameter. The first non-builtin, non-special typed parameter must be the event class."
            );
        }

        // F5: return type must be void
        $returnType = $method->getReturnType();
        if (!($returnType instanceof ReflectionNamedType && $returnType->getName() === 'void')) {
            throw new LogicException("Handler '$context' must have a void return type (F5).");
        }

        $eventClass = $eventParam[0]['eventClass'];

        $descriptor = [
            'handlerId'  => $attribute->handlerId,
            'serviceId'  => $serviceId,
            'method'     => $method->getName(),
            'priority'   => $attribute->priority,
            'idempotent' => $attribute->idempotent,
            'version'    => $attribute->version,
            'eventClass' => $eventClass,
            'parameters' => $parameters,
        ];

        $handlers = $container->getParameter('vortos.handlers');

        foreach ($handlers[$attribute->consumer] ?? [] as $descriptors) {
            foreach ($descriptors as $existing) {
                if ($existing['handlerId'] === $attribute->handlerId) {
                    throw new LogicException(sprintf(
                        'Duplicate handlerId "%s" on consumer "%s": already registered by "%s". Each handler on a consumer must have a unique handlerId.',
                        $attribute->handlerId,
                        $attribute->consumer,
                        $existing['serviceId'],
                    ));
                }
            }
        }

        $handlers[$attribute->consumer][$eventClass][] = $descriptor;
        $container->setParameter('vortos.handlers', $handlers);

        $this->registerWireMapping($container, $attribute, $eventClass, $context);
    }

    /**
     * Merges #[AsEventHandler(event: '...')] declarations into the global
     * wire-name → local-class map. The handler's parameter type IS the local
     * contract class the wire payload deserializes into — the consuming
     * module's own class, never the producer's.
     */
    private function registerWireMapping(
        ContainerBuilder $container,
        AsEventHandler $attribute,
        string $eventClass,
        string $context,
    ): void {
        if ($attribute->event === null) {
            return;
        }

        $wireEventMap = $container->hasParameter('vortos.wire_event_map')
            ? $container->getParameter('vortos.wire_event_map')
            : [];

        $existing = $wireEventMap[$attribute->event] ?? null;

        if ($existing !== null && $existing !== $eventClass) {
            // A producer-derived default is overridden silently (that is the
            // point: the consumer declares its own contract). But two handlers
            // claiming the same wire name with DIFFERENT local classes is a
            // real conflict the build must reject — unless one comes from an
            // explicit handles() declaration, which we cannot distinguish here,
            // so the rule is: handler declarations must agree with whatever is
            // already registered, except when overriding a producer default.
            $eventWireMap = $container->hasParameter('vortos.event_wire_map')
                ? $container->getParameter('vortos.event_wire_map')
                : [];
            $isProducerDefault = ($eventWireMap[$existing]['name'] ?? null) === $attribute->event;

            if (!$isProducerDefault) {
                throw new LogicException(sprintf(
                    "Handler '%s' maps wire event '%s' to '%s' but it is already mapped to '%s'. A wire name resolves to exactly one local class per process.",
                    $context,
                    $attribute->event,
                    $eventClass,
                    $existing,
                ));
            }
        }

        $wireEventMap[$attribute->event] = $eventClass;
        $container->setParameter('vortos.wire_event_map', $wireEventMap);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveHandlerParameters(ReflectionMethod $method, string $context): array
    {
        $parameters = [];
        $eventFound = false;

        foreach ($method->getParameters() as $param) {
            // Named header stamp attributes
            foreach ([MessageId::class, CorrelationId::class, CausationId::class, TraceId::class, Timestamp::class, TenantId::class, UserId::class] as $attrClass) {
                if ($param->getAttributes($attrClass) !== []) {
                    $parameters[] = [
                        'type'      => 'header',
                        'attribute' => $attrClass,
                        'paramType' => $param->getType()?->getName() ?? 'string',
                    ];
                    continue 2;
                }
            }

            // Generic #[Header('name')]
            $headerAttrs = $param->getAttributes(Header::class);
            if ($headerAttrs !== []) {
                $attr = $headerAttrs[0]->newInstance();
                $parameters[] = [
                    'type'       => 'header',
                    'attribute'  => Header::class,
                    'headerName' => $attr->name,
                    'paramType'  => $param->getType()?->getName() ?? 'string',
                ];
                continue;
            }

            $type = $param->getType();

            // Untyped or builtin — skip (cannot inject)
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $typeName = $type->getName();

            if ($typeName === EventEnvelope::class) {
                $parameters[] = ['type' => 'envelope'];
                continue;
            }

            if ($typeName === Metadata::class) {
                $parameters[] = ['type' => 'metadata'];
                continue;
            }

            // F4: second non-special typed param after event param is forbidden
            if ($eventFound) {
                throw new LogicException(
                    "$context: F4 violation — parameter '\${$param->getName()}' of type '$typeName' is not allowed after the event param. " .
                    "Only EventEnvelope, Metadata, or header-attributed params are permitted after the event."
                );
            }

            if (!class_exists($typeName)) {
                throw new LogicException("$context: parameter type '$typeName' does not exist.");
            }

            $this->assertValidEventClass($typeName, $context);
            $eventFound = true;
            $parameters[] = ['type' => 'event', 'eventClass' => $typeName];
        }

        return $parameters;
    }

    private function assertValidEventClass(string $className, string $context): void
    {
        $refl = new ReflectionClass($className);

        // F1: must be final
        if (!$refl->isFinal()) {
            throw new LogicException(
                "$context: F1 violation — event class '$className' must be declared final."
            );
        }

        // F3: no methods other than __construct
        foreach ($refl->getMethods() as $method) {
            if ($method->getName() !== '__construct') {
                throw new LogicException(
                    "$context: F3 violation — event class '$className' must have no methods other than __construct. " .
                    "Found: {$method->getName()}()."
                );
            }
        }

        // F2: all own properties must be public, readonly, and constructor-promoted
        foreach ($refl->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $className) {
                continue;
            }
            if (!$property->isPublic() || !$property->isReadOnly() || !$property->isPromoted()) {
                throw new LogicException(
                    "$context: F2 violation — all properties of event class '$className' must be public readonly " .
                    "constructor-promoted. Property '\${$property->getName()}' violates this."
                );
            }
        }
    }
}
