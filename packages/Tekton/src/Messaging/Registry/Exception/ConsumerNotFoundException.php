<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry\Exception;

use RuntimeException;

/**
 * Thrown when a consumer name cannot be resolved in the ConsumerRegistry.
 * This is a programming error — all consumers must be registered at compile time
 * via #[RegisterConsumer] in a #[MessagingConfig] class.
 */
final class ConsumerNotFoundException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(
            "Consumer '{$name}' is not registered. Did you forget #[RegisterConsumer] in your MessagingConfig?"
        );
    }
}
