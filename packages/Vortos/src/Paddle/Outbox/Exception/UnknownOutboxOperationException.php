<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox\Exception;

final class UnknownOutboxOperationException extends \RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(sprintf('No handler registered for Paddle outbox operation "%s".', $operation));
    }
}
