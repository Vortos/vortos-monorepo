<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Bus;

use DateTimeImmutable;
use DateTimeInterface;
use Fortizan\Tekton\Messaging\Bus\Stamp\CorrelationIdStamp;
use Fortizan\Tekton\Messaging\Bus\Stamp\EventIdStamp;
use Fortizan\Tekton\Messaging\Bus\Stamp\TimestampStamp;
use Fortizan\Tekton\Messaging\Contract\DomainEventInterface;
use Fortizan\Tekton\Messaging\Contract\EventBusInterface;
use Fortizan\Tekton\Messaging\Contract\OutboxInterface;
use Fortizan\Tekton\Messaging\Contract\ProducerInterface;
use Fortizan\Tekton\Messaging\Hook\HookRunner;
use Fortizan\Tekton\Messaging\Registry\HandlerRegistry;
use Fortizan\Tekton\Messaging\Registry\ProducerRegistry;
use Fortizan\Tekton\Tracing\Contract\TracingInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Internal in-process event bus. Wraps Symfony Messenger for handler dispatch
 * and coordinates outbox/direct broker production for external delivery.
 * 
 * For each dispatched event:
 * - If internal handlers exist in HandlerRegistry → dispatches via Symfony Messenger bus
 * - If a producer is registered for this event class → routes to outbox or direct broker
 * - Both paths can execute for the same event simultaneously
 * - If neither exists → logs a warning as this is likely a misconfiguration
 * 
 * The outbox store() call must happen within an active database transaction.
 * Callers are responsible for transaction boundaries when dispatching outside
 * of a TransactionalMiddleware-wrapped handler.
 */
final class EventBus implements EventBusInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private OutboxInterface $outbox,
        private ProducerInterface $producer,
        private HandlerRegistry $handlerRegistry,
        private ProducerRegistry $producerRegistry,
        private array $eventProducerMap,
        private HookRunner $hookRunner,
        private LoggerInterface $logger,
        private TracingInterface $tracer
    ){
    }

    public function dispatch(DomainEventInterface $event): void
    {
        $throwable = null;
        try {

            $eventId = bin2hex(random_bytes(16));

            $correlationId = $this->tracer->currentCorrelationId() ?? bin2hex(random_bytes(16));

            $eventIdStamp = new EventIdStamp($eventId);
            $timestampStamp = new TimestampStamp(new DateTimeImmutable());
            $correlationIdStamp = new CorrelationIdStamp($correlationId);

            $headers = [
                'event_id'       => $eventId,
                'correlation_id' => $correlationId,
                'event_class'    => get_class($event),
                'timestamp'      => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];

            $envelope = new Envelope($event, [
                $eventIdStamp,
                $timestampStamp,
                $correlationIdStamp
            ]);

            $this->hookRunner->runBeforeDispatch($event);

            $eventClass = get_class($event);

            $hasHandlers = $this->hasInternalHandlers($eventClass);

            if ($hasHandlers) {
                $this->bus->dispatch($envelope);
            }

            $producerName = $this->eventProducerMap[$eventClass] ?? null;

            if ($producerName !== null) {

                $producerDefinition = $this->producerRegistry->get($producerName);

                $producerConfig = $producerDefinition->toArray();

                $this->hookRunner->runPreSend($event, $headers);

                $outboxEnabled = $producerConfig['outbox']['enabled'] ?? true;

                if ($outboxEnabled) {
                    $this->outbox->store(
                        $event,
                        $producerConfig['transport'] ?? '',
                        $headers
                    );
                } else {
                    $this->producer->produce(
                        $producerConfig['transport'] ?? '',
                        $event,
                        $headers
                    );
                }
            }

            if (!$hasHandlers && $producerName === null) {
                $this->logger->warning(
                    'Event dispatched but no handlers or producer registered',
                    ['event' => $eventClass]
                );
            }
        } catch (\Throwable $e) {
            $throwable = $e;
            throw $e;
        } finally {
            $this->hookRunner->runAfterDispatch($event, $throwable);
        }
    }

    public function dispatchBatch(DomainEventInterface ...$events): void
    {
        foreach($events as $event){
            $this->dispatch($event);
        }
    }

    /**
     * Returns true if any consumer has at least one handler registered for this event class.
     * Determines whether the event should be dispatched through the internal Symfony Messenger bus.
     */
    private function hasInternalHandlers(string $eventClass): bool
    {
        foreach($this->handlerRegistry->allConsumers() as $consumerName){
            if($this->handlerRegistry->hasHandlers($consumerName, $eventClass)){
                return true;
            }
        }

        return false;
    }
}