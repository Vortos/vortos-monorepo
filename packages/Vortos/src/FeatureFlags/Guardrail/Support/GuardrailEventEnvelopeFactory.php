<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Support;

use Symfony\Component\Uid\Uuid;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;

/**
 * Wraps a raw guardrail domain event payload in an {@see EventEnvelope} for the bus.
 * Every guardrail event carries a public string $policyId used as the aggregate id.
 */
final class GuardrailEventEnvelopeFactory
{
    public static function wrap(object $payload, \DateTimeImmutable $occurredAt): EventEnvelope
    {
        /** @var string $policyId */
        $policyId = $payload->policyId ?? '';

        return new EventEnvelope(
            eventId:          Uuid::v7()->toRfc4122(),
            aggregateId:      $policyId,
            aggregateType:    GuardrailPolicy::class,
            aggregateVersion: 1,
            payloadType:      $payload::class,
            schemaVersion:    1,
            occurredAt:       $occurredAt,
            payload:          $payload,
            metadata:         Metadata::empty(),
        );
    }
}
