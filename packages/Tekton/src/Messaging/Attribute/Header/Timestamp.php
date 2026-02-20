<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the message timestamp from the envelope into the marked handler parameter.
 *
 * The parameter type must be DateTimeImmutable.
 * The value is extracted from TimestampStamp on the Symfony Messenger envelope.
 * Injection is performed by the middleware pipeline before the handler is invoked.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[Timestamp] DateTimeImmutable $occurredAt): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Timestamp 
{

}
