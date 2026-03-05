<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook\Attribute;

use Attribute;

/**
 * Fires inside HookMiddleware before the handler callable is invoked. 
 * Receives the Envelope with all stamps populated — EventIdStamp, CorrelationIdStamp, ConsumerStamp are all available. 
 * Fires after TracingMiddleware and LoggingMiddleware have run so trace context is restored and logging context is set. 
 * Use for pre-processing validation, setting request-scoped context (tenant, user), or additional logging.
 * 
 * This hook fires inside the middleware stack, 
 * which means it fires once per handler invocation if a message has multiple handlers. 
 * It does not fire once per message. If you need once-per-message semantics, use a different mechanism.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class BeforeConsume
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?string $consumer = null,
        public readonly int $priority = 0
    ) {}
}
