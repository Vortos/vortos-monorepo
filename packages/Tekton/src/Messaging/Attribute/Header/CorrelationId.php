<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the correlation ID from the envelope into the marked handler parameter.
 *
 * The parameter type must be string.
 * The value is extracted from CorrelationIdStamp on the Symfony Messenger envelope.
 * Used for distributed tracing — chain this ID through all downstream events and logs.
 * Injection is performed by the middleware pipeline before the handler is invoked.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[CorrelationId] string $correlationId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CorrelationId 
{
    
}
