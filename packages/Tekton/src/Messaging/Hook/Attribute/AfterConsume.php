<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook\Attribute;

use Attribute;

/**
 * Fires inside HookMiddleware after the handler callable returns successfully. 
 * Receives the Envelope. 
 * When $onFailureOnly = true does not fire on success — only fires when OnFailure would fire. 
 * Use for post-processing metrics, cache warming,
 * or any logic that should run after confirmed successful handler execution.
 * 
 * This fires after the handler but before TransactionalMiddleware commits — 
 * because HookMiddleware wraps the call but TransactionalMiddleware is deeper in the stack. 
 * If your hook does a database write it participates in the same transaction as the handler. 
 * This is intentional and correct for audit trail hooks.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AfterConsume
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?string $consumer = null,
        public readonly int $priority = 0,
        public readonly bool $onFailureOnly = false
    ) {}
}
