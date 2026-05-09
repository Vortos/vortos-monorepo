<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\Timestamp;
use Vortos\Messaging\Bus\Stamp\ConsumerStamp;
use Vortos\Messaging\Bus\Stamp\CorrelationIdStamp;
use Vortos\Messaging\Bus\Stamp\EventIdStamp;
use Vortos\Messaging\Bus\Stamp\TimestampStamp;
use Vortos\Messaging\Contract\ConsumerInterface;
use Vortos\Messaging\Contract\ConsumerLocatorInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\DeadLetter\DeadLetterWriter;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Retry\RetryDecider;
use Vortos\Messaging\Retry\RetryPolicy;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\ValueObject\ReceivedMessage;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\UuidV7;

/**
 * Orchestrates the full message processing pipeline for a named consumer.
 * 
 * Builds the handler callable passed to ConsumerInterface::consume().
 * For each received message: deserializes the payload, resolves handlers
 * from HandlerRegistry, checks idempotency, runs each handler through the
 * MiddlewareStack, retries on failure, writes to dead letter after exhaustion,
 * and acknowledges or rejects the message based on overall outcome.
 * 
 * This is the central coordinator of the consumer side. It owns no I/O —
 * all broker communication is delegated to ConsumerInterface, all storage
 * to DeadLetterWriter and CacheInterface.
 */
final class ConsumerRunner
{
    private ?ConsumerInterface $activeConsumer = null;

    public function __construct(
        private HandlerRegistry $handlerRegistry,
        private SerializerLocator $serializerLocator,
        private MiddlewareStack $middlewareStack,
        private DeadLetterWriter $deadLetterWriter,
        private ProducerInterface $producer,
        private CacheInterface $cache,
        private ConsumerLocatorInterface $consumerLocator,
        private LoggerInterface $logger,
        private ServiceLocator $handlerLocator,
        private RetryDecider $retryDecider,
        private ConsumerRegistry $consumerRegistry,
        private int $defaultIdempotencyTtl = 86400,
    ) {}

    public function run(string $consumerName, int $maxMessages = 0): void
    {
        $this->activeConsumer = $this->consumerLocator->get($consumerName);
        $processed = 0;

        $this->activeConsumer->consume(
            $consumerName,
            function (ReceivedMessage $message) use ($consumerName, &$processed, $maxMessages): void {
                $this->handleMessage($consumerName, $message, $this->activeConsumer);
                $processed++;
                if ($maxMessages > 0 && $processed >= $maxMessages) {
                    $this->activeConsumer->stop();
                }
            }
        );
    }

    /** Stops the consumer loop. Called by signal handlers on SIGTERM/SIGINT. */
    public function stop(): void
    {
        $this->activeConsumer?->stop();
    }

    private function handleMessage(string $consumerName, ReceivedMessage $message, ConsumerInterface $consumer): void
    {
        $eventClass = $message->headers['event_class'] ?? null;

        if ($eventClass === null) {
            $this->logger->warning(
                'Received message with no event_class header'
            );

            $consumer->reject($message, false);

            return;
        }

        $descriptors = $this->handlerRegistry->getHandlers($consumerName, $eventClass);

        if (empty($descriptors)) {
            $this->logger->warning(
                'No handlers found for event',
                [
                    'consumer' => $consumerName,
                    'event_class' => $eventClass
                ]
            );

            $consumer->acknowledge($message);

            return;
        }

        $serializer = $this->serializerLocator->locate('json');

        try {

            $event = $serializer->deserialize($message->payload, $eventClass);
        } catch (\Throwable $e) {

            $this->logger->error(
                'Failed to deserialize message',
                [
                    'event_class' => $eventClass,
                    'exception' => $e
                ]
            );

            $consumer->reject($message, false);

            return;
        }

        $eventId = $message->headers['event_id'] ?? (new UuidV7())->toRfc4122();
        $correlationId = $message->headers['correlation_id'] ?? (new UuidV7())->toRfc4122();

        $envelope = new Envelope(
            $event,
            [
                new EventIdStamp($eventId),
                new CorrelationIdStamp($correlationId),
                new ConsumerStamp($consumerName)
            ]
        );

        $allSucceeded = true;

        foreach ($descriptors as $descriptor) {
            $succeeded = $this->processHandler($consumerName, $descriptor, $envelope, $message);

            if (!$succeeded) {
                $allSucceeded = false;
            }
        }

        if ($allSucceeded) {
            $consumer->acknowledge($message);
        } else {
            $consumer->reject($message, false);
        }
    }

    private function processHandler(string $consumerName, array $descriptor, Envelope $envelope, ReceivedMessage $message): bool
    {
        $eventId = $envelope->last(EventIdStamp::class)?->eventId ?? null;

        $cacheKey = null;
        if ($eventId !== null) {
            $cacheKey = 'vortos_idempotency_' . $descriptor['handlerId'] . '_' . $eventId;

            if ($this->cache->has($cacheKey) && !$descriptor['idempotent']) {
                $this->logger->debug('Skipping duplicate handler execution');
                return true;
            }
        }
       
        $handlerService = $this->handlerLocator->get($descriptor['serviceId']);

        $handlerCallable = function (Envelope $e) use ($handlerService, $descriptor): Envelope {
            $handlerService->{$descriptor['method']}(
                ...$this->resolveArguments($descriptor, $e)
            );
            return $e;
        };

        try {
            $consumerConfig = $this->consumerRegistry->get($consumerName);
        } catch (\Throwable) {
            $consumerConfig = [];
        }

        $idempotencyTtl = $consumerConfig['idempotencyTtl'] ?? $this->defaultIdempotencyTtl;

        try {
            $this->middlewareStack->process($envelope, $handlerCallable);

            if (isset($cacheKey)) {
                $this->cache->set($cacheKey, true, $idempotencyTtl);
            }

            return true;
        } catch (\Throwable $e) {
            $retryArray = $consumerConfig['retry'] ?? [];
            $retryPolicy = !empty($retryArray)
                ? RetryPolicy::fromArray($retryArray)
                : RetryPolicy::exponential(attempts: 3, initialDelayMs: 500);

            $attempt = 1;
            $lastException = $e;

            while ($this->retryDecider->shouldRetry($retryPolicy, $attempt)) {
                $delay = $this->retryDecider->getDelayMs($retryPolicy, $attempt);

                usleep($delay * 1000);

                try {
                    $this->middlewareStack->process($envelope, $handlerCallable);

                    if (isset($cacheKey)) {
                        $this->cache->set($cacheKey, true, $idempotencyTtl);
                    }

                    return true;
                } catch (\Throwable $e) {
                    $attempt++;
                    $lastException = $e;
                }
            }

            $this->deadLetterWriter->write(
                transportName: $message->transportName,
                eventClass: $message->headers['event_class'] ?? '',
                payload: $message->payload,
                headers: $message->headers,
                failureReason: $lastException->getMessage(),
                exceptionClass: get_class($lastException),
                attemptCount: 1 + $retryPolicy->maxAttempts
            );

            $dlqTransport = $consumerConfig['dlq'] ?? '';
            if ($dlqTransport !== '') {
                try {
                    $this->producer->produce($dlqTransport, $envelope->getMessage(), $message->headers);
                } catch (\Throwable $dlqException) {
                    $this->logger->error('Failed to produce message to DLQ transport', [
                        'dlq_transport' => $dlqTransport,
                        'exception'     => $dlqException->getMessage(),
                    ]);
                }
            }

            return false;
        }
    }

    private function resolveArguments(array $descriptor, Envelope $envelope): array
    {
        $args = [];

        foreach ($descriptor['parameters'] as $param) {
            if ($param['type'] === 'event') {
                $args[] = $envelope->getMessage();
                continue;
            }

            // header injection
            $args[] = match ($param['attribute']) {
                MessageId::class     => $envelope->last(EventIdStamp::class)?->eventId ?? '',
                CorrelationId::class => $envelope->last(CorrelationIdStamp::class)?->correlationId ?? '',
                Timestamp::class     => $envelope->last(TimestampStamp::class)?->occurredAt ?? new \DateTimeImmutable(),
                default              => null,
            };
        }

        return $args;
    }
}
