<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook\Attribute;

use Attribute;

/**
 * Fires in ConsumerRunner immediately before each handler is processed —
 * outside the middleware stack, once per handler per message.
 *
 * Fires even for handlers that will be idempotency-skipped or replay-discarded,
 * so you always get a matching AfterHandler call for every BeforeHandler call.
 *
 * Receives the EventEnvelope, consumer name, and handler ID.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class BeforeHandler
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?string $consumer = null,
        public readonly int $priority = 0
    ) {}
}
