<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

/**
 * Handles the actual HTTP delivery of a signed webhook payload (Block 18).
 * Implementations should handle retry/backoff and record delivery attempts.
 */
interface WebhookDeliveryInterface
{
    /**
     * Deliver a signed webhook payload to a subscription's URL.
     *
     * @param string|null $resolvedIp the pre-resolved IP (pin this — do not re-resolve)
     */
    public function deliver(WebhookPayload $payload, WebhookSubscription $subscription, ?string $resolvedIp = null): void;
}
