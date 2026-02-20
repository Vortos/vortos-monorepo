<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the message ID from the envelope into the marked handler parameter.
 *
 * The parameter type must be string.
 * The value is extracted from EventIdStamp on the Symfony Messenger envelope.
 * Injection is performed by the middleware pipeline before the handler is invoked.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[MessageId] string $messageId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class MessageId
{

}