<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook\Attribute;

use Attribute;

/**
 * Fires after EventBus::dispatch() completes — after internal handlers ran and after outbox write or direct produce. 
 * Receives the DomainEventInterface and a ?Throwable — null on success, 
 * the exception on failure. 
 * The exception is passed for observation only — the hook must not swallow it or re-throw a different one. 
 * If $onFailureOnly = true, only fires when $throwable !== null.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class AfterDispatch
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly int $priority = 0,
        public readonly bool $onFailureOnly = false
    ){
    }
}