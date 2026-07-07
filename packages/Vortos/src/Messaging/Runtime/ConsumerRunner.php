<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Attribute\Header\CausationId;
use Vortos\Messaging\Attribute\Header\CorrelationId;
use Vortos\Messaging\Attribute\Header\MessageId;
use Vortos\Messaging\Attribute\Header\TenantId;
use Vortos\Messaging\Attribute\Header\Timestamp;
use Vortos\Messaging\Attribute\Header\TraceId;
use Vortos\Messaging\Attribute\Header\UserId;
use Vortos\Messaging\Bus\Stamp\ConsumerStamp;
use Vortos\Messaging\Bus\Stamp\CorrelationIdStamp;
use Vortos\Messaging\Bus\Stamp\EventEnvelopeStamp;
use Vortos\Messaging\Bus\Stamp\EventIdStamp;
use Vortos\Messaging\Bus\Stamp\TimestampStamp;
use Vortos\Messaging\Contract\ConsumerInterface;
use Vortos\Messaging\Contract\ConsumerLocatorInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\DeadLetter\DeadLetterWriter;
use Vortos\Messaging\Definition\WireNaming;
use Vortos\Messaging\Exception\DeadLetterWriteException;
use Vortos\Messaging\Middleware\MiddlewareStack;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Hook\HandlerOutcome;
use Vortos\Messaging\Hook\HookRunner;
use Vortos\Messaging\Retry\RetryDecider;
use Vortos\Messaging\Retry\RetryPolicy;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Messaging\Upcasting\UpcasterChain;
use Vortos\Messaging\ValueObject\ReceivedMessage;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\TelemetryLabels;
use Psr\Log\LoggerInterface;
use Vortos\Cache\Contract\AtomicCacheInterface;
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
final class ConsumerRunner implements ConsumerRunnerInterface
{
    private ?ConsumerInterface $activeConsumer = null;
    private string $replaySecret = '';
    private bool $draining = false;

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
        private AtomicCacheInterface $cache,
        private ConsumerLocatorInterface $consumerLocator,
        private LoggerInterface $logger,
        private ServiceLocator $handlerLocator,
        private RetryDecider $retryDecider,
        private ConsumerRegistry $consumerRegistry,
        private int $defaultIdempotencyTtl = 86400,
        private ?FrameworkTelemetry $telemetry = null,
        private ?TracingInterface $tracer = null,
        private ?HookRunner $hookRunner = null,
        private ?EventBusInterface $eventBus = null,
        /** @var array<string, class-string> logical wire name → local contract class */
        private array $wireEventMap = [],
        /** @var array<string, array<int, class-string>> wire name → [fromVersion => upcaster class] */
        private array $upcasterMap = [],
        // Push-based telemetry (OTLP/StatsD) is only delivered when flush() runs. HTTP requests
        // flush on kernel.terminate, but a consumer worker runs a single long-lived command whose
        // ConsoleEvents::TERMINATE fires only at process exit — so without an in-loop flush a daemon
        // consumer would accumulate metrics in memory and never export them. This throttled flush
        // drains telemetry periodically while the loop runs. No-op for pull-based (Prometheus) and
        // NoOp adapters via ModuleAwareMetrics::flush().
        private ?FlushableMetricsInterface $metricsFlusher = null,
        private int $telemetryFlushIntervalMs = 5000,
    ) {
        $this->upcasterChain = new UpcasterChain($this->upcasterMap);
        $this->telemetryFlushIntervalMs = max(250, $this->telemetryFlushIntervalMs);
    }

    private UpcasterChain $upcasterChain;
    private int $lastTelemetryFlushNs = 0;

    public function run(string $consumerName, int $maxMessages = 0): void
    {
        $this->activeConsumer = $this->consumerLocator->get($consumerName);
        $processed = 0;
        // Start the interval clock now so the first flush honours the full interval rather than
        // firing after the very first message.
        $this->lastTelemetryFlushNs = hrtime(true);

        try {
            $this->activeConsumer->consume(
                $consumerName,
                function (ReceivedMessage $message) use ($consumerName, &$processed, $maxMessages): void {
                    $this->handleMessage($consumerName, $message, $this->activeConsumer);
                    $processed++;
                    $this->flushTelemetry();
                    if ($maxMessages > 0 && $processed >= $maxMessages) {
                        $this->activeConsumer->stop();
                    }
                }
            );
        } finally {
            // Final drain when the loop ends (drain/stop/max-messages/exception), so the last
            // interval's metrics are delivered even if the process exits before ConsoleEvents::TERMINATE.
            $this->flushTelemetry(force: true);
        }
    }

    /**
     * Drains push-based telemetry, at most once per configured interval.
     *
     * Called after every processed message; the throttle keeps a high-throughput consumer from
     * issuing one OTLP export per message while still delivering metrics continuously. A closed
     * or failed export is surfaced by the adapter's own flush(), never thrown here — telemetry
     * delivery must not be able to crash the consumer loop.
     */
    private function flushTelemetry(bool $force = false): void
    {
        if ($this->metricsFlusher === null) {
            return;
        }

        $now = hrtime(true);
        if (!$force
            && ($now - $this->lastTelemetryFlushNs) < $this->telemetryFlushIntervalMs * 1_000_000
        ) {
            return;
        }

        $this->lastTelemetryFlushNs = $now;

        try {
            $this->metricsFlusher->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Telemetry flush failed during consume loop', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /** Stops the consumer loop. Called by signal handlers on SIGTERM/SIGINT. */
    public function stop(): void
    {
        $this->draining = true;
        $this->activeConsumer?->stop();
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    private function handleMessage(string $consumerName, ReceivedMessage $message, ConsumerInterface $consumer): void
    {
        $start = hrtime(true);
        $wirePayloadType = $message->headers['payload_type'] ?? null;
        $eventName = $wirePayloadType !== null ? TelemetryLabels::classShortName($wirePayloadType) : 'unknown';
        $consumerLabel = TelemetryLabels::safe($consumerName);
        $span = $this->tracer?->startSpan('messaging.consume', [
            'vortos.module' => ObservabilityModule::Messaging,
            'messaging.operation' => 'process',
            'messaging.destination.name' => TelemetryLabels::safe($message->transportName),
            'messaging.consumer.name' => $consumerLabel,
            'messaging.message.type' => $eventName,
        ]);

        if ($wirePayloadType === null) {
            $this->logger->warning('Received message with no payload_type header');
            $consumer->reject($message, false);
            $this->recordMessage($consumerLabel, $eventName, 'rejected', $start);
            $span?->setStatus('error');
            $span?->end();
            return;
        }

        // Closed-world contract resolution: the wire carries a logical name
        // ('registration.entry_approved.v1') which must resolve through OUR
        // compiled map to a local class. The wire can never select a class
        // directly — a forged FQCN in payload_type has no map entry and is
        // rejected without instantiating anything.
        [$wireName, $schemaVersion] = WireNaming::parse($wirePayloadType);
        $payloadType = $this->wireEventMap[$wireName] ?? null;

        if ($payloadType === null) {
            $this->logger->error('Unknown wire contract — no local class mapped for event name', [
                'consumer'     => $consumerName,
                'payload_type' => $wirePayloadType,
                'wire_name'    => $wireName,
            ]);
            $consumer->reject($message, false);
            $this->recordMessage($consumerLabel, $eventName, 'unknown_contract', $start);
            $span?->setStatus('error');
            $span?->end();
            return;
        }

        $descriptors = $this->handlerRegistry->getHandlers($consumerName, $payloadType);

        if (empty($descriptors)) {
            $this->logger->warning('No handlers found for event', [
                'consumer'     => $consumerName,
                'payload_type' => $payloadType,
            ]);
            $consumer->acknowledge($message);
            $this->recordMessage($consumerLabel, $eventName, 'ignored', $start);
            $span?->setStatus('ok');
            $span?->end();
            return;
        }

        $serializer = $this->serializerLocator->locate('json');

        try {
            // Lift older schema versions to the shape this consumer speaks
            // BEFORE hydration — v1 messages keep flowing after a v2 bump.
            $wirePayload = $message->payload;

            if ($this->upcasterChain->hasStepsFor($wireName)) {
                $decoded = json_decode($wirePayload, true, 512, JSON_THROW_ON_ERROR);
                [$decoded, $schemaVersion] = $this->upcasterChain->upcast($wireName, $schemaVersion, $decoded);
                $wirePayload = json_encode($decoded, JSON_THROW_ON_ERROR);
            }

            $payload = $serializer->deserialize($wirePayload, $payloadType);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to deserialize message', [
                'payload_type' => $payloadType,
                'exception'    => $e,
            ]);
            $consumer->reject($message, false);
            $this->recordMessage($consumerLabel, $eventName, 'deserialize_failed', $start);
            $span?->recordException($e);
            $span?->setStatus('error');
            $span?->end();
            return;
        }

        $eventId       = $message->headers['event_id'] ?? (new UuidV7())->toRfc4122();
        $correlationId = $message->headers['correlation_id'] ?? (new UuidV7())->toRfc4122();

        // Reconstruct the full domain EventEnvelope from wire headers + deserialized payload.
        // Built before the Messenger envelope so it can be attached as a stamp.
        $occurredAt = isset($message->headers['occurred_at'])
            ? new \DateTimeImmutable($message->headers['occurred_at'])
            : new \DateTimeImmutable();

        $domainEnvelope = new EventEnvelope(
            eventId:          $eventId,
            aggregateId:      $message->headers['aggregate_id'] ?? '',
            aggregateType:    $message->headers['aggregate_type'] ?? '',
            aggregateVersion: (int) ($message->headers['aggregate_version'] ?? 0),
            payloadType:      $payloadType,
            schemaVersion:    $schemaVersion,
            occurredAt:       $occurredAt,
            payload:          $payload,
            metadata:         new Metadata(
                correlationId: $message->headers['correlation_id'] ?: null,
                causationId:   $message->headers['causation_id'] ?: null,
                traceId:       $message->headers['trace_id'] ?: null,
                tenantId:      $message->headers['tenant_id'] ?? null,
                userId:        $message->headers['user_id'] ?? null,
            ),
        );

        $messengerEnvelope = new Envelope(
            $payload,
            [
                new EventIdStamp($eventId),
                new CorrelationIdStamp($correlationId),
                new ConsumerStamp($consumerName),
                new HeadersStamp($message->headers),
                new EventEnvelopeStamp($domainEnvelope),
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
                $succeeded = $this->processHandler(
                    $consumerName, $descriptor, $messengerEnvelope, $domainEnvelope, $message, $isTrustedReplay
                );
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

        $this->hookRunner?->runAfterConsume($domainEnvelope, $consumerName);

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

    private function processHandler(string $consumerName, array $descriptor, Envelope $envelope, EventEnvelope $domainEnvelope, ReceivedMessage $message, bool $isTrustedReplay = false): bool
    {
        $start     = hrtime(true);
        $handlerId = $descriptor['handlerId'];
        $eventId   = $envelope->last(EventIdStamp::class)?->eventId ?? null;

        $this->hookRunner?->runBeforeHandler($domainEnvelope, $consumerName, $handlerId);

        // Load consumer config before the idempotency claim — TTL is needed for setNx
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

        // Atomic idempotency claim for non-idempotent handlers — eliminates TOCTOU race
        $cacheKey = null;
        if ($eventId !== null) {
            $cacheKey = 'vortos_idempotency_' . $descriptor['handlerId'] . '_' . $eventId;

            if (!$descriptor['idempotent']) {
                if (!$this->cache->setNx($cacheKey, true, $idempotencyTtl)) {
                    $this->logger->debug('Skipping duplicate handler execution');
                    $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::SkippedIdempotent, 1, 0.0);
                    return true;
                }
            }
        }

        $handlerService = $this->handlerLocator->get($descriptor['serviceId']);

        $handlerCallable = function (Envelope $e) use ($handlerService, $descriptor, $domainEnvelope): Envelope {
            // Ledger scope brackets the handler so aggregates mutated here get their
            // events collected and dispatched inside the same transaction
            // (TransactionalMiddleware wraps this callable). Runs innermost so it is
            // independent of middleware ordering.
            $ledger = DomainEventLedger::instance();
            $isRoot = $ledger->open();

            try {
                $handlerService->{$descriptor['method']}(
                    ...$this->resolveArguments($descriptor, $e, $domainEnvelope)
                );

                if ($isRoot && $this->eventBus !== null) {
                    while ($ledger->hasPending()) {
                        foreach ($ledger->drain() as $recordedEnvelope) {
                            $this->eventBus->dispatch($recordedEnvelope);
                        }
                    }
                }
            } finally {
                $ledger->close();
            }

            return $e;
        };

        // H-37: cast to int — Kafka header values arrive as strings; non-numeric strings
        // would compare as 0 under PHP loose comparison, bypassing the replay limit.
        $globalReplays = $isTrustedReplay ? (int) ($message->headers['x-vortos-global-replays'] ?? 0) : 0;

        if ($globalReplays > $retryPolicy->maxReplayLimit) {
            $eventName = TelemetryLabels::classShortName($domainEnvelope->payloadType);
            $consumerLabel = TelemetryLabels::safe($consumerName);

            $this->logger->error("Hard discarding message: exceeded global replay limit.", [
                'handler'      => $handlerId,
                'payload_type' => $domainEnvelope->payloadType,
                'limit'        => $retryPolicy->maxReplayLimit,
            ]);

            $this->recordMessage($consumerLabel, $eventName, 'discarded', $start);
            $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::DiscardedReplayLimit, 1, (hrtime(true) - $start) / 1_000_000);
            return true;
        }

        try {
            $this->middlewareStack->process($envelope, $handlerCallable);

            // Idempotent handlers: set the tracking key on success (non-idempotent key was already claimed via setNx)
            if ($cacheKey !== null && $descriptor['idempotent']) {
                $this->cache->set($cacheKey, true, $idempotencyTtl);
            }

            $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::Succeeded, 1, (hrtime(true) - $start) / 1_000_000);
            return true;
        } catch (\Throwable $e) {

            $attempt = 1;
            $lastException = $e;

            $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::AttemptFailed, 1, (hrtime(true) - $start) / 1_000_000, $e);

            $maxPollIntervalMs = $consumerConfig['kafka']['maxPollIntervalMs'] ?? 300000;
            $delayCap = (int) ($maxPollIntervalMs / max(1, $retryPolicy->maxAttempts));

            while (!$this->draining && $this->retryDecider->shouldRetry($retryPolicy, $attempt)) {
                $delay = min($this->retryDecider->getDelayMs($retryPolicy, $attempt), $delayCap);

                usleep($delay * 1000);

                try {
                    $this->middlewareStack->process($envelope, $handlerCallable);

                    if ($cacheKey !== null && $descriptor['idempotent']) {
                        $this->cache->set($cacheKey, true, $idempotencyTtl);
                    }

                    $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::SucceededAfterRetries, $attempt + 1, (hrtime(true) - $start) / 1_000_000);
                    return true;
                } catch (\Throwable $e) {
                    $this->telemetry?->increment(
                        ObservabilityModule::Messaging,
                        FrameworkMetric::MessagingMessageRetriesTotal,
                        FrameworkMetricLabels::of(
                            MetricLabelValue::of(MetricLabel::Consumer, $consumerName),
                            MetricLabelValue::of(MetricLabel::Event, TelemetryLabels::classShortName($domainEnvelope->payloadType)),
                        ),
                    );
                    $attempt++;
                    $lastException = $e;
                    $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::AttemptFailed, $attempt, (hrtime(true) - $start) / 1_000_000, $e);
                }
            }

            // All retries exhausted — release the idempotency claim so the message can be reprocessed
            if ($cacheKey !== null && !$descriptor['idempotent']) {
                $this->cache->delete($cacheKey);
            }

            $written = $this->deadLetterWriter->write(
                transportName:  $message->transportName,
                eventClass:     $domainEnvelope->payloadType,
                handlerId:      $handlerId,
                payload:        $message->payload,
                headers:        $message->headers,
                failureReason:  $lastException->getMessage(),
                exceptionClass: get_class($lastException),
                attemptCount:   1 + $retryPolicy->maxAttempts,
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

            $this->hookRunner?->runAfterHandler($domainEnvelope, $consumerName, $handlerId, HandlerOutcome::DeadLettered, $attempt, (hrtime(true) - $start) / 1_000_000, $lastException);
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

    private function resolveArguments(array $descriptor, Envelope $envelope, EventEnvelope $domainEnvelope): array
    {
        $args = [];
        $allHeaders = $envelope->last(HeadersStamp::class)?->headers ?? [];

        foreach ($descriptor['parameters'] as $param) {
            if ($param['type'] === 'event') {
                $args[] = $envelope->getMessage();
                continue;
            }

            if ($param['type'] === 'envelope') {
                $args[] = $domainEnvelope;
                continue;
            }

            if ($param['type'] === 'metadata') {
                $args[] = $domainEnvelope->metadata;
                continue;
            }

            if ($param['attribute'] === Header::class) {
                $args[] = $allHeaders[$param['headerName']] ?? null;
                continue;
            }

            // header stamp / metadata injection
            $args[] = match ($param['attribute']) {
                MessageId::class     => $envelope->last(EventIdStamp::class)?->eventId ?? '',
                CorrelationId::class => $envelope->last(CorrelationIdStamp::class)?->correlationId ?? '',
                Timestamp::class     => $envelope->last(TimestampStamp::class)?->occurredAt ?? new \DateTimeImmutable(),
                CausationId::class   => $domainEnvelope->metadata->causationId,
                TraceId::class       => $domainEnvelope->metadata->traceId,
                TenantId::class      => $domainEnvelope->metadata->tenantId,
                UserId::class        => $domainEnvelope->metadata->userId,
                default              => null,
            };
        }

        return $args;
    }
}
