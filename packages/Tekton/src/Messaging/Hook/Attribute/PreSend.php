<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook\Attribute;

use Attribute;

/**
 * Fires inside EventBus::dispatch() after the envelope is built but before the outbox write or direct produce call. 
 * This is the last point where headers can be mutated before the message leaves the process. 
 * The hook receives the DomainEventInterface and the array $headers by reference — mutating 
 * $headers inside the hook affects what gets written to the outbox. 
 * Use this for injecting custom headers, adding tenant IDs, or enriching the message envelope before external delivery.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class PreSend
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly int $priority = 0
    ) {}
}
