<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\UuidV7;
use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Enum\FailureMode;
use Vortos\Audit\Event\AuditEvent;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Messaging\Contract\EventBusInterface;

/**
 * Decouples the request path from the chain append: instead of writing synchronously,
 * it dispatches an {@see AuditEventRecorded} envelope onto the bus (→ outbox → Kafka),
 * where the ingestion consumer appends it under the per-chain lock. The request never
 * blocks on audit storage.
 *
 * On a dispatch failure the configured {@see FailureMode} decides between blocking the
 * caller (compliance-critical) and dropping with a log line (availability-first).
 */
final class AsyncAuditRecorder implements AuditRecorderInterface
{
    public function __construct(
        private readonly EventBusInterface $eventBus,
        private readonly FailureMode       $failureMode = FailureMode::Block,
        private readonly LoggerInterface   $logger = new NullLogger(),
    ) {}

    public function record(AuditEvent $event): void
    {
        try {
            $this->eventBus->dispatch(new EventEnvelope(
                eventId:          (string) new UuidV7(),
                aggregateId:      $event->id,
                aggregateType:    'AuditEvent',
                aggregateVersion: 0,
                payloadType:      AuditEventRecorded::class,
                schemaVersion:    1,
                occurredAt:       $event->occurredAt,
                payload:          AuditEventRecorded::fromEvent($event),
                metadata:         Metadata::empty(),
            ));
        } catch (\Throwable $e) {
            if ($this->failureMode === FailureMode::Block) {
                throw $e;
            }

            $this->logger->error('Audit event dropped: failed to enqueue for ingestion.', [
                'audit_id'     => $event->id,
                'audit_action' => $event->action,
                'exception'    => $e->getMessage(),
            ]);
        }
    }
}
