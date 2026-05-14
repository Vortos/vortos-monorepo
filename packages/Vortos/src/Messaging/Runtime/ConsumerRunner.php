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
use Vortos\Messaging\Exception\DeadLetterWriteException;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Retry\RetryDecider;
use Vortos\Messaging\Retry\RetryPolicy;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\ValueObject\ReceivedMessage;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\TelemetryLabels;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\UuidV7;
use Vortos\Messaging\Attribute\Header\Header;
use Vortos\Messaging\Bus\Stamp\HeadersStamp;
use Vortos\Tracing\Contract\TracingInterface;

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
    private string $replaySecret = '';

    public function setReplaySecret(string $secret): void
    {
        $this->replaySecret = $secret;
    }

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
        private ?FrameworkTelemetry $telemetry = null,
        private ?TracingInterface $tracer = null,
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
        $start = hrtime(true);
        $eventClass = $message->headers['event_class'] ?? null;
        $eventName = $eventClass !== null ? TelemetryLabels::classShortName($eventClass) : 'unknown';
        $consumerLabel = TelemetryLabels::safe($consumerName);
        $span = $this->tracer?->startSpan('messaging.consume', [
            'vortos.module' => ObservabilityModule::Messaging,
            'messaging.operation' => 'process',
            'messaging.destination.name' => TelemetryLabels::safe($message->transportName),
            'messaging.consumer.name' => $consumerLabel,
            'messaging.message.type' => $eventName,
        ]);

        if ($eventClass === null) {
            $this->logger->warning(
                'Received message with no event_class header'
            );

            $consumer->reject($message, false);
            $this->recordMessage($consumerLabel, $eventName, 'rejected', $start);
            $span?->setStatus('error');
            $span?->end();

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
            $this->recordMessage($consumerLabel, $eventName, 'ignored', $start);
            $span?->setStatus('ok');
            $span?->end();

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
            $this->recordMessage($consumerLabel, $eventName, 'deserialize_failed', $start);
            $span?->recordException($e);
            $span?->setStatus('error');
            $span?->end();

            return;
        }

        $eventId = $message->headers['event_id'] ?? (new UuidV7())->toRfc4122();
        $correlationId = $message->headers['correlation_id'] ?? (new UuidV7())->toRfc4122();

        $envelope = new Envelope(
            $event,
            [
                new EventIdStamp($eventId),
                new CorrelationIdStamp($correlationId),
                new ConsumerStamp($consumerName),
                new HeadersStamp($message->headers)
            ]
        );

        $allSucceeded = true;

        $target = $message->headers['x-vortos-target-handler'] ?? null;
        $replaySig = $message->headers['x-vortos-replay-sig'] ?? '';
        $isTrustedReplay = $this->replaySecret !== ''
            && $target !== null
            && hash_equals(hash_hmac('sha256', $target, $this->replaySecret), $replaySig);

        if ($isTrustedReplay) {
            $descriptors = array_filter($descriptors, fn($d) => $d['handlerId'] === $target);
        }

        try {
            foreach ($descriptors as $descriptor) {
                $succeeded = $this->processHandler($consumerName, $descriptor, $envelope, $message, $isTrustedReplay);

                if (!$succeeded) {
                    $allSucceeded = false;
                }
            }
        } catch (DeadLetterWriteException $e) {
            $this->logger->error('Dead letter write failed; offset not committed', ['exception' => $e->getMessage()]);
            $span?->setStatus('error');
            $span?->end();
            return;
        }

        if ($allSucceeded) {
            $consumer->acknowledge($message);
            $this->recordMessage($consumerLabel, $eventName, 'acknowledged', $start);
            $span?->setStatus('ok');
        } else {
            $consumer->reject($message, false);
            $this->recordMessage($consumerLabel, $eventName, 'dead_lettered', $start);
            $span?->setStatus('error');
        }

        $span?->end();
    }

    private function processHandler(string $consumerName, array $descriptor, Envelope $envelope, ReceivedMessage $message, bool $isTrustedReplay = false): bool
    {
        $start = hrtime(true);
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

        $retryArray = $consumerConfig['retry'] ?? [];
        $retryPolicy = !empty($retryArray)
            ? RetryPolicy::fromArray($retryArray)
            : RetryPolicy::exponential(attempts: 3, initialDelayMs: 500);

        $idempotencyTtl = $consumerConfig['idempotencyTtl'] ?? $this->defaultIdempotencyTtl;

        $globalReplays = $isTrustedReplay ? ($message->headers['x-vortos-global-replays'] ?? 0) : 0;

        if ($globalReplays > $retryPolicy->maxReplayLimit) {
            $eventClass = $message->headers['event_class'] ?? 'unknown';
            $eventName = TelemetryLabels::classShortName($eventClass);
            $consumerLabel = TelemetryLabels::safe($consumerName);

            $this->logger->error("Hard discarding message: exceeded global replay limit.", [
                'handler' => $descriptor['handlerId'],
                'event' => $eventClass,
                'limit' => $retryPolicy->maxReplayLimit
            ]);

            $this->recordMessage($consumerLabel, $eventName, 'discarded', $start);
            return true;
        }

        try {
            $this->middlewareStack->process($envelope, $handlerCallable);

            if (isset($cacheKey)) {
                $this->cache->set($cacheKey, true, $idempotencyTtl);
            }

            return true;
        } catch (\Throwable $e) {

            $attempt = 1;
            $lastException = $e;

            $maxPollIntervalMs = $consumerConfig['kafka']['maxPollIntervalMs'] ?? 300000;
            $delayCap = (int) ($maxPollIntervalMs / max(1, $retryPolicy->maxAttempts));

            while ($this->retryDecider->shouldRetry($retryPolicy, $attempt)) {
                $delay = min($this->retryDecider->getDelayMs($retryPolicy, $attempt), $delayCap);

                usleep($delay * 1000);

                try {
                    $this->middlewareStack->process($envelope, $handlerCallable);

                    if (isset($cacheKey)) {
                        $this->cache->set($cacheKey, true, $idempotencyTtl);
                    }

                    return true;
                } catch (\Throwable $e) {
                    $this->telemetry?->increment(
                        ObservabilityModule::Messaging,
                        FrameworkMetric::MessagingMessageRetriesTotal,
                        FrameworkMetricLabels::of(
                            MetricLabelValue::of(MetricLabel::Consumer, $consumerName),
                            MetricLabelValue::of(MetricLabel::Event, TelemetryLabels::classShortName($message->headers['event_class'] ?? 'unknown')),
                        ),
                    );
                    $attempt++;
                    $lastException = $e;
                }
            }

            $written = $this->deadLetterWriter->write(
                transportName: $message->transportName,
                eventClass: $message->headers['event_class'] ?? '',
                handlerId: $descriptor['handlerId'],
                payload: $message->payload,
                headers: $message->headers,
                failureReason: $lastException->getMessage(),
                exceptionClass: get_class($lastException),
                attemptCount: 1 + $retryPolicy->maxAttempts
            );

            if (!$written) {
                throw new DeadLetterWriteException('Dead letter persistence failed; Kafka offset will not be committed.');
            }

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

    private function recordMessage(string $consumer, string $event, string $result, int $start): void
    {
        $resultLabels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Consumer, $consumer),
            MetricLabelValue::of(MetricLabel::Event, $event),
            MetricLabelValue::of(MetricLabel::Result, $result),
        );
        $durationLabels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Consumer, $consumer),
            MetricLabelValue::of(MetricLabel::Event, $event),
        );

        $this->telemetry?->increment(ObservabilityModule::Messaging, FrameworkMetric::MessagingMessagesConsumedTotal, $resultLabels);
        $this->telemetry?->observe(ObservabilityModule::Messaging, FrameworkMetric::MessagingMessageDurationMs, $durationLabels, (hrtime(true) - $start) / 1_000_000);
    }

    private function resolveArguments(array $descriptor, Envelope $envelope): array
    {
        $args = [];
        $allHeaders = $envelope->last(HeadersStamp::class)?->headers ?? [];

        foreach ($descriptor['parameters'] as $param) {
            if ($param['type'] === 'event') {
                $args[] = $envelope->getMessage();
                continue;
            }

            if ($param['attribute'] === Header::class) {
                $args[] = $allHeaders[$param['headerName']] ?? null;
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
