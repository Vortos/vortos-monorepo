<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Support;

use Symfony\Component\Uid\Uuid;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;

/**
 * Wraps a raw change-request domain event payload in an {@see EventEnvelope} for the bus.
 * Change requests are not {@see \Vortos\Domain\AggregateRoot}s, so they build envelopes
 * here rather than via {@see \Vortos\Domain\AggregateRoot::recordEvent()}.
 */
final class EventEnvelopeFactory
{
    public static function wrap(string $aggregateId, object $payload, \DateTimeImmutable $occurredAt): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          Uuid::v7()->toRfc4122(),
            aggregateId:      $aggregateId,
            aggregateType:    ChangeRequest::class,
            aggregateVersion: 1,
            payloadType:      $payload::class,
            schemaVersion:    1,
            occurredAt:       $occurredAt,
            payload:          $payload,
            metadata:         Metadata::empty(),
        );
    }
}
