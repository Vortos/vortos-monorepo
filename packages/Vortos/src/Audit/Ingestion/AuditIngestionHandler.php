<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion;

use Vortos\Audit\Event\AuditEvent;
use Vortos\Messaging\Attribute\AsEventHandler;

/**
 * Consumer entrypoint. Runs inside the `vortos.audit` consumer pipeline (declared in the
 * app's messaging config); the framework routes each delivered {@see AuditEventRecorded}
 * to this handler, which appends it to its chain via the processor.
 *
 * Declared idempotent: the processor + DB primary key make reprocessing safe, so the
 * messaging middleware may retry freely.
 */
final class AuditIngestionHandler
{
    public function __construct(private readonly AuditIngestionProcessor $processor) {}

    #[AsEventHandler(handlerId: 'vortos.audit.ingest', consumer: 'vortos.audit', idempotent: true)]
    public function __invoke(AuditEventRecorded $message): void
    {
        $this->processor->process(AuditEvent::fromArray($message->event));
    }
}
