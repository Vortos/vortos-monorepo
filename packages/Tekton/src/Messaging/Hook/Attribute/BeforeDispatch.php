<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook\Attribute;

use Attribute;

/**
 * This attribute marks a class as a hook that fires immediately before EventBus::dispatch() 
 * calls any internal handlers or writes to the outbox. 
 * The hook receives the raw DomainEventInterface before the envelope is built. 
 * Use this for pre-dispatch validation, audit initiation, 
 * or enrichment that must happen before any internal or external delivery.
 * 
 * The hook fires before the envelope exists — stamps are not available here. 
 * If you need stamps, use AfterDispatch instead where the envelope has already been built.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class BeforeDispatch
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly int $priority = 0 
    ){
    }
}