<?php

declare(strict_types=1);

namespace Vortos\Security\Signing\Attribute;

use Attribute;

/**
 * Requires an HMAC request signature on an endpoint.
 *
 * Designed for incoming webhooks from providers (Stripe, GitHub, Shopify, etc.)
 * that sign their payloads.
 *
 * ## Signature format
 *
 * The provider sends an HMAC-SHA256 hex digest in a header. Some providers
 * prefix it (e.g. Stripe sends `sha256=<hex>`). Both bare hex and prefixed
 * formats are handled automatically.
 *
 * ## Replay protection
 *
 * When $timestampHeader is set, the middleware validates that the request
 * timestamp is within $replayWindowSeconds of now. Prevents replayed requests.
 *
 * ## Secret resolution
 *
 * $secret can be:
 *   - A plain string:              'my-secret'
 *   - An env: reference:           'env:STRIPE_WEBHOOK_SECRET'
 *   - A secrets: reference:        'secrets:stripe/webhook_secret'
 *
 * Example — Stripe webhook:
 *
 *   #[RequiresSignature(
 *       secret: 'env:STRIPE_WEBHOOK_SECRET',
 *       header: 'Stripe-Signature',
 *       timestampHeader: 'Stripe-Signature', // Stripe embeds t= in the same header
 *       replayWindowSeconds: 300,
 *   )]
 *   #[SkipCsrf]
 *   class StripeWebhookController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequiresSignature
{
    public function __construct(
        public readonly string $secret,
        public readonly string $header              = 'X-Signature-256',
        public readonly string $timestampHeader     = '',
        public readonly int    $replayWindowSeconds = 300,
        public readonly string $algorithm           = 'sha256',
    ) {}
}
