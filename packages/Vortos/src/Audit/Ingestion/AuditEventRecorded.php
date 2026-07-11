<?php

declare(strict_types=1);

namespace Vortos\Audit\Ingestion;

/**
 * Wire contract carried over the message bus from the request path (producer) to the
 * ingestion consumer. A pure, final readonly POPO with no methods beyond the constructor
 * (the messaging wire-contract rules forbid extra methods on an event class) — the
 * producer passes {@see AuditEvent::toArray()} and the consumer rebuilds it via
 * {@see AuditEvent::fromArray()}. Transporting the fully-assembled event as an array
 * keeps the chain computed exactly once, in the consumer, under the per-chain lock.
 */
final readonly class AuditEventRecorded
{
    /**
     * @param array<string, mixed> $event AuditEvent::toArray()
     */
    public function __construct(
        public string $auditId,
        public array  $event,
    ) {}
}
