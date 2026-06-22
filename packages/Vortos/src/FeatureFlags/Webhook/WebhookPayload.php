<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

/**
 * A signed webhook delivery payload (Block 18).
 */
final class WebhookPayload
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $eventType,
        public readonly array $data,
        public readonly string $timestamp,
        public readonly string $deliveryId,
    ) {}

    public function body(): string
    {
        return json_encode([
            'event'       => $this->eventType,
            'data'        => $this->data,
            'timestamp'   => $this->timestamp,
            'delivery_id' => $this->deliveryId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Compute the HMAC-SHA256 signature for a webhook delivery.
     * Format: `sha256=<hex>` (GitHub/Stripe convention).
     */
    public static function sign(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * Verify a signature against the body and secret.
     */
    public static function verifySignature(string $body, string $secret, string $signature): bool
    {
        $expected = self::sign($body, $secret);
        return hash_equals($expected, $signature);
    }
}
