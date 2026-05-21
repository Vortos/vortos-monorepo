<?php

declare(strict_types=1);

namespace Vortos\Cqrs\Http\Attribute;

use Attribute;

/**
 * Marks a controller as requiring an Idempotency-Key header.
 *
 * IdempotencyKeyMiddleware checks for this attribute at runtime (using a list
 * pre-built by IdempotencyKeyMiddlewarePass at compile time). If the header is
 * absent the middleware returns HTTP 422 before the controller is invoked.
 *
 * ## Usage
 *
 *   #[AsController]
 *   #[Route('/api/payments', methods: ['POST'])]
 *   #[RequiresAuth]
 *   #[RequiresIdempotencyKey]
 *   final class CreatePaymentController { ... }
 *
 * ## When to use
 *
 * Apply to any state-changing endpoint where duplicate execution would cause
 * harm — payments, order creation, account registration. The CommandBus will
 * replay the cached result for any duplicate within the 24-hour TTL window.
 *
 * ## Client contract
 *
 * Clients must generate a UUID v4/v7 per logical operation and send it as:
 *   Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
 *
 * The same key on a retry returns the original response. A new key always
 * executes the command fresh.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RequiresIdempotencyKey {}
