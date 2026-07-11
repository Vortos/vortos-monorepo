<?php

declare(strict_types=1);

namespace Vortos\Audit\Contract;

use Vortos\Audit\Event\AuditEvent;

/**
 * The sink an assembled {@see AuditEvent} is handed to.
 *
 * This is the pluggable seam: P1 ships Null + Buffering implementations; P3 provides
 * the Kafka-decoupled recorder (enqueue → worker appends the chain) so the request
 * path never blocks on the audit write. Apps depend on {@see AuditTrailInterface}, not
 * this — this is the infrastructure boundary.
 */
interface AuditRecorderInterface
{
    public function record(AuditEvent $event): void;
}
