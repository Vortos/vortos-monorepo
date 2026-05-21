<?php

declare(strict_types=1);

namespace Vortos\Domain\Event;

/**
 * Typed metadata carried alongside an event payload in an EventEnvelope.
 *
 * Fields are first-class and typed — adding a new field is an intentional
 * framework change and tracked. The `custom` array is an escape hatch for
 * application-specific metadata that does not warrant a permanent field.
 *
 * Metadata is set by the EventBus and lifecycle hooks during dispatch.
 * Aggregates produce envelopes with Metadata::empty(); enrichment happens
 * at the framework boundary (tracer injects correlation, hooks add tenant).
 *
 * Lives in the Domain layer because Metadata is part of the envelope's
 * shape and Domain owns EventEnvelope. All fields are opaque strings or
 * arrays — Domain has no knowledge of how they are sourced or routed.
 */
final readonly class Metadata
{
    public function __construct(
        public ?string $correlationId = null,
        public ?string $causationId   = null,
        public ?string $traceId       = null,
        public ?string $tenantId      = null,
        public ?string $userId        = null,
        public array   $custom        = [],
    ) {}

    public static function empty(): self
    {
        return new self();
    }
}
