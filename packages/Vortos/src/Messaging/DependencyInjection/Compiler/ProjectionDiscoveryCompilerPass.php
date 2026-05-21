<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cqrs\Attribute\AsProjectionHandler;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Attribute\Header\CausationId;
use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\Header;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\TenantId;
use Vortos\Messaging\Attribute\Header\Timestamp;
use Vortos\Messaging\Attribute\Header\TraceId;
use Vortos\Messaging\Attribute\Header\UserId;

/**
 * Discovers all classes tagged 'vortos.projection_handler' and registers
 * their descriptors into 'vortos.handlers' — the same map HandlerRegistry reads.
 *
 * Projection handlers are always idempotent. Enforces the same compile-time
 * safety rules as HandlerDiscoveryCompilerPass:
 *   F1 — event class must be final
 *   F2 — all properties must be public readonly constructor-promoted
 *   F3 — class must have no methods other than __construct
 *   F4 — after the event param, only EventEnvelope, Metadata, or
 *        header-attributed params are allowed
 *   F5 — handler method return type must be : void
 *
 * Runs at priority 85 — after HandlerDiscovery (90) so both passes write
 * into the same vortos.handlers parameter without conflict.
 */
final class ProjectionDiscoveryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('vortos.handlers')) {
            $container->setParameter('vortos.handlers', []);
        }

        $taggedHandlers = $container->findTaggedServiceIds('vortos.projection_handler');

        foreach ($taggedHandlers as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $className = $container->getDefinition($serviceId)->getClass();
                $reflClass = new ReflectionClass($className);

                $method = $tag['method'] ?? '__invoke';

                if (!$reflClass->hasMethod($method)) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" has no method "%s".',
                        $className,
                        $method,
                    ));
                }

                $reflMethod = $reflClass->getMethod($method);
                $context    = "$className::$method";
                $parameters = $this->resolveHandlerParameters($reflMethod, $context);

                $eventParam = array_values(array_filter($parameters, fn($p) => $p['type'] === 'event'));

                if (empty($eventParam)) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s::%s()" must have an event parameter as its first non-special typed parameter.',
                        $className,
                        $method,
                    ));
                }

                // F5: return type must be void
                $returnType = $reflMethod->getReturnType();
                if (!($returnType instanceof ReflectionNamedType && $returnType->getName() === 'void')) {
                    throw new \LogicException("Projection handler '$context' must have a void return type (F5).");
                }

                $eventClass = $eventParam[0]['eventClass'];

                $consumer  = $tag['consumer'] ?? null;
                $handlerId = $tag['handlerId'] ?? null;

                if ($consumer === null || $handlerId === null) {
                    throw new \LogicException(sprintf(
                        'Projection handler "%s" tag is missing consumer or handlerId.',
                        $className,
                    ));
                }

                $descriptor = [
                    'handlerId'    => $handlerId,
                    'serviceId'    => $serviceId,
                    'method'       => $method,
                    'priority'     => $tag['priority'] ?? 0,
                    'idempotent'   => true,
                    'version'      => $tag['version'] ?? null,
                    'eventClass'   => $eventClass,
                    'isProjection' => true,
                    'parameters'   => $parameters,
                ];

                $handlers = $container->getParameter('vortos.handlers');

                foreach ($handlers[$consumer] ?? [] as $descriptors) {
                    foreach ($descriptors as $existing) {
                        if ($existing['handlerId'] === $handlerId) {
                            throw new \LogicException(sprintf(
                                'Duplicate handlerId "%s" on consumer "%s": already registered by "%s". Each handler on a consumer must have a unique handlerId.',
                                $handlerId,
                                $consumer,
                                $existing['serviceId'],
                            ));
                        }
                    }
                }

                $handlers[$consumer][$eventClass][] = $descriptor;
                $container->setParameter('vortos.handlers', $handlers);
            }
        }

        // Ensure projection handler services are in the handler locator so
        // SyncProjectionEventBusDecorator can resolve them at runtime.
        if ($container->hasDefinition('vortos.handler_locator')) {
            $currentArgs = $container->getDefinition('vortos.handler_locator')->getArgument(0);
            foreach ($taggedHandlers as $serviceId => $_) {
                $currentArgs[$serviceId] = new Reference($serviceId);
            }
            $container->getDefinition('vortos.handler_locator')->setArguments([$currentArgs]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveHandlerParameters(\ReflectionMethod $method, string $context): array
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
                throw new \LogicException(
                    "$context: F4 violation — parameter '\${$param->getName()}' of type '$typeName' is not allowed after the event param. " .
                    "Only EventEnvelope, Metadata, or header-attributed params are permitted after the event."
                );
            }

            if (!class_exists($typeName)) {
                throw new \LogicException("$context: parameter type '$typeName' does not exist.");
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
            throw new \LogicException(
                "$context: F1 violation — event class '$className' must be declared final."
            );
        }

        // F3: no methods other than __construct
        foreach ($refl->getMethods() as $method) {
            if ($method->getName() !== '__construct') {
                throw new \LogicException(
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
                throw new \LogicException(
                    "$context: F2 violation — all properties of event class '$className' must be public readonly " .
                    "constructor-promoted. Property '\${$property->getName()}' violates this."
                );
            }
        }
    }
}
