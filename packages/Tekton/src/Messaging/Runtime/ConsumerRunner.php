<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Runtime;

use Fortizan\Tekton\Messaging\Bus\Stamp\ConsumerStamp;
use Fortizan\Tekton\Messaging\Bus\Stamp\CorrelationIdStamp;
use Fortizan\Tekton\Messaging\Bus\Stamp\EventIdStamp;
use Fortizan\Tekton\Messaging\Contract\ConsumerInterface;
use Fortizan\Tekton\Messaging\DeadLetter\DeadLetterWriter;
use Fortizan\Tekton\Messaging\Middleware\MiddlewareStack;
use Fortizan\Tekton\Messaging\Registry\HandlerRegistry;
use Fortizan\Tekton\Messaging\Serializer\SerializerLocator;
use Fortizan\Tekton\Messaging\ValueObject\ReceivedMessage;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Envelope;

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
    public function __construct(
        private HandlerRegistry $handlerRegistry,
        private SerializerLocator $serializerLocator,
        private MiddlewareStack $middlewareStack,
        private DeadLetterWriter $deadLetterWriter,
        private CacheInterface $cache,
        private ConsumerInterface $consumer,
        private LoggerInterface $logger,
        private ContainerInterface $container,
        private int $idempotencyTtl = 86400
    ) {}

    public function run(string $consumerName): void
    {
        $this->consumer->consume(
            $consumerName,
            fn(ReceivedMessage $message) => $this->handleMessage($consumerName, $message)
        );
    }

    /** Stops the consumer loop. Called by signal handlers on SIGTERM/SIGINT. */
    public function stop():void
    {
        $this->consumer->stop();
    }

    private function handleMessage(string $consumerName, ReceivedMessage $message): void
    {
        $eventClass = $message->headers['event_class'] ?? null;

        if ($eventClass === null) {
            $this->logger->warning(
                'Received message with no event_class header'
            );

            $this->consumer->reject($message, false);

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

            $this->consumer->reject($message, false);

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

            $this->consumer->acknowledge($message);

            return;
        }

        $eventId = $message->headers['event_id'] ?? bin2hex(random_bytes(8));
        $correlationId = $message->headers['correlation_id'] ?? bin2hex(random_bytes(8));

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
            $succeeded = $this->processHandler($descriptor, $envelope, $message);

            if (!$succeeded) {
                $allSucceeded = false;
            }
        }

        if ($allSucceeded) {
            $this->consumer->acknowledge($message);
        } else {
            $this->consumer->reject($message, false);
        }
    }

    private function processHandler(array $descriptor, Envelope $envelope, ReceivedMessage $message): bool
    {
        $eventId = $envelope->last(EventIdStamp::class)?->eventId ?? null;

        $cacheKey = null;
        if ($eventId !== null) {
            $cacheKey = 'tekton_idempotency_' . $descriptor['handlerId'] . '_' . $eventId;

            if ($this->cache->has($cacheKey) && !$descriptor['idempotent']) {
                $this->logger->debug('Skipping duplicate handler execution');
                return true;
            }
        }

        $handlerService = $this->container->get($descriptor['serviceId']);

        $handlerCallable = fn(Envelope $e) => $handlerService->{$descriptor['method']}(
            $e->getMessage(),
            // header params resolved separately — for now just pass the event
        );

        try {
            $this->middlewareStack->process($envelope, $handlerCallable);

            if (isset($cacheKey)) {
                $this->cache->set($cacheKey, true, $this->idempotencyTtl);
            }

            return true;
        } catch (\Throwable $e) {

            $attempt = 1;
            $lastException = $e;

            while ($attempt <= 3) {
                sleep(1);
                try {
                    $this->middlewareStack->process($envelope, $handlerCallable);
                    if ($eventId !== null) {

                        if (isset($cacheKey)) {
                            $this->cache->set($cacheKey, true, $this->idempotencyTtl);
                        }
                    }
                    return true;
                } catch (\Throwable $retryException) {
                    $attempt++;
                    $lastException = $retryException;
                }
            }

            $this->deadLetterWriter->write(
                transportName: $message->transportName,
                eventClass: $message->headers['event_class'],
                payload: $message->payload,
                headers: $message->headers,
                failureReason: $lastException->getMessage(),
                exceptionClass: get_class($lastException),
                attemptCount: 4  // 1 original + 3 retries
            );

            return false;
        }
    }
}
