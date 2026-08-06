<?php

declare(strict_types=1);

namespace Vortos\Payments\Webhook;

/**
 * A verified inbound event, normalised across rails.
 *
 * Only ever constructed after signature verification has passed. Nothing
 * downstream re-checks, so nothing upstream may skip it.
 *
 * `id` is the rail's own event identifier and is what an inbox de-duplicates
 * on. Every rail redelivers — that is a feature, it is how a webhook survives
 * our downtime — so the id has to be stable across redeliveries of the same
 * event, and an adapter that cannot find one must synthesise it from the
 * fields that identify the payment rather than from the clock.
 */
final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload The rail's own decoded body, verbatim.
     */
    public function __construct(
        public string             $id,
        /** The rail's event name, verbatim. Adapters map it; consumers switch on it. */
        public string             $type,
        public \DateTimeImmutable $occurredAt,
        public array              $payload,
        /** Our charge reference, when the rail echoes one. */
        public ?string            $reference = null,
        /** The rail's own charge reference. */
        public ?string            $gatewayReference = null,
    ) {
        if (trim($id) === '') {
            throw new \InvalidArgumentException(
                'A webhook event needs a stable id; without one an inbox cannot tell a redelivery from a second payment.'
            );
        }
    }
}
