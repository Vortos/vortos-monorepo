<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook\Attribute;

use Attribute;

/**
 * This attribute marks a class as a hook that fires immediately before EventBus::dispatch() 
 * calls any internal handlers or writes to the outbox. 
 * The hook receives the EventEnvelope built by the aggregate.
 * Use this for pre-dispatch validation, audit initiation,
 * or enrichment that must happen before any internal or external delivery.
 *
 * If you need Symfony Messenger stamps, use AfterDispatch instead where the
 * Messenger envelope has already been built.
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