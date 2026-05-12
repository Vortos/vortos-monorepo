<?php

declare(strict_types=1);

namespace Vortos\Messaging\Bus;

use DateTimeImmutable;
use DateTimeInterface;
use Vortos\Messaging\Bus\Stamp\CorrelationIdStamp;
use Vortos\Messaging\Bus\Stamp\EventIdStamp;
use Vortos\Messaging\Bus\Stamp\TimestampStamp;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Contract\OutboxInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Hook\HookRunner;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\ProducerRegistry;
use Vortos\Tracing\Contract\TracingInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\UuidV7;
use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Registry\ConsumerRegistry;

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
    /** @var array<string, bool> event class → true for event classes that have at least one in-process consumer */
    private array $inProcessEventClasses;

    public function __construct(
        private MessageBusInterface $bus,
        private OutboxInterface $outbox,
        private ProducerInterface $producer,
        private HandlerRegistry $handlerRegistry,
        private ProducerRegistry $producerRegistry,
        private array $eventProducerMap,
        private HookRunner $hookRunner,
        private LoggerInterface $logger,
        private ?TracingInterface $tracer,
        private ConsumerRegistry $consumerRegistry,
    ) {
        $this->inProcessEventClasses = $this->buildInProcessEventClassIndex();
    }

    public function dispatch(DomainEventInterface $event): void
    {
        $throwable = null;
        try {

            $eventId = (new UuidV7())->toRfc4122();

            $correlationId = $this->tracer?->currentCorrelationId() ?? (new UuidV7())->toRfc4122();

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

            $hasHandlers = isset($this->inProcessEventClasses[$eventClass]);

            if ($hasHandlers) {
                $this->bus->dispatch($envelope);
            }

            $producerName = $this->eventProducerMap[$eventClass] ?? null;

            if ($producerName !== null) {

                $producerConfig = $this->producerRegistry->get($producerName);

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
     * Builds an index of event classes that have at least one in-process consumer handler.
     * Called once at construction — O(consumers × event_classes) at startup, O(1) at dispatch time.
     */
    private function buildInProcessEventClassIndex(): array
    {
        $index = [];

        foreach ($this->handlerRegistry->allConsumers() as $consumerName) {
            try {
                $consumerConfig = $this->consumerRegistry->get($consumerName);
            } catch (\Throwable) {
                continue;
            }

            if (!($consumerConfig['inProcess'] ?? false)) {
                continue;
            }

            foreach (array_keys($this->handlerRegistry->allForConsumer($consumerName)) as $eventClass) {
                $index[$eventClass] = true;
            }
        }

        return $index;
    }
}
