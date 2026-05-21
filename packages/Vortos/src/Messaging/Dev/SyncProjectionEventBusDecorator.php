<?php

declare(strict_types=1);

namespace Vortos\Messaging\Dev;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Registry\HandlerRegistry;

/**
 * Dev/test decorator that synchronously invokes projection handlers immediately
 * after each envelope is dispatched to the real EventBus.
 *
 * In production, projections are updated asynchronously: the envelope is
 * written to the outbox, relayed to Kafka, consumed by a worker, and then the
 * projection handler runs. In tests and local dev this latency makes it
 * impossible to assert on read-model state immediately after a command — you
 * would need arbitrary sleeps or polling.
 *
 * This decorator solves that: after the real dispatch() returns, it looks up
 * all projection handlers for the envelope's payload type across every
 * consumer and calls them in-process synchronously.
 *
 * Parameter injection is type-based via reflection:
 *   - First non-builtin param matching the payload class → $envelope->payload
 *   - EventEnvelope → $envelope
 *   - Metadata → $envelope->metadata
 *
 * MessagingExtension registers this as a Symfony decorator for
 * EventBusInterface in dev and test environments only. Set
 * VORTOS_SYNC_PROJECTIONS=false to opt out.
 *
 * Projection errors are logged and swallowed — the same way a real Kafka
 * consumer handles transient failures before DLQ.
 */
final class SyncProjectionEventBusDecorator implements EventBusInterface
{
    /** @var array<string, list<mixed>> Reflection cache: "ServiceId::method" → resolved arg list shape */
    private array $paramCache = [];

    public function __construct(
        private readonly EventBusInterface $inner,
        private readonly HandlerRegistry $handlerRegistry,
        private readonly ServiceLocator $handlerLocator,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatchBatch(EventEnvelope ...$envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->dispatch($envelope);
        }
    }

    public function dispatch(EventEnvelope $envelope): void
    {
        $this->inner->dispatch($envelope);

        $payloadType = $envelope->payloadType;

        foreach ($this->handlerRegistry->allConsumers() as $consumer) {
            $descriptors = $this->handlerRegistry->getHandlers($consumer, $payloadType);

            foreach ($descriptors as $descriptor) {
                if (!($descriptor['isProjection'] ?? false)) {
                    continue;
                }

                $serviceId = $descriptor['serviceId'];
                $method    = $descriptor['method'] ?? '__invoke';

                if (!$this->handlerLocator->has($serviceId)) {
                    continue;
                }

                try {
                    $handler = $this->handlerLocator->get($serviceId);
                    $args    = $this->resolveArgs($handler, $method, $envelope);
                    $handler->$method(...$args);
                } catch (\Throwable $e) {
                    $this->logger->warning('SyncProjectionEventBusDecorator: projection failed (swallowed)', [
                        'consumer'     => $consumer,
                        'handler'      => $serviceId,
                        'payload_type' => $payloadType,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Resolve arguments for a handler method using reflection.
     *
     * Injection rules (in order, per parameter):
     *   1. Type is EventEnvelope → inject $envelope
     *   2. Type is Metadata      → inject $envelope->metadata
     *   3. Type matches payload class (or any non-builtin class) → inject $envelope->payload
     *   4. Otherwise             → inject null
     *
     * @return list<mixed>
     */
    private function resolveArgs(object $handler, string $method, EventEnvelope $envelope): array
    {
        $ref    = new \ReflectionMethod($handler, $method);
        $params = $ref->getParameters();

        if (count($params) === 0) {
            return [];
        }

        $args = [];

        foreach ($params as $param) {
            $type = $param->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                $args[] = $param->isOptional() ? $param->getDefaultValue() : null;
                continue;
            }

            $typeName = $type->getName();

            $args[] = match ($typeName) {
                EventEnvelope::class => $envelope,
                Metadata::class      => $envelope->metadata,
                default              => $envelope->payload,
            };
        }

        return $args;
    }
}
