<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Domain\Event;

/**
 * Emitted when a triggered guardrail's metric recovers but the policy has
 * ack_required=true, so it cannot auto-resolve — a human must call /ack.
 */
final readonly class GuardrailPendingAckEvent
{
    public function __construct(
        public string $policyId,
        public string $flagName,
        public string $environment,
        public \DateTimeImmutable $at,
    ) {}
}
