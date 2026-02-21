<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry\Exception;

use RuntimeException;

/**
 * Thrown when a producer name cannot be resolved in the ProducerRegistry.
 * This is a programming error — all producers must be registered at compile time
 * via #[RegisterProducer] in a #[MessagingConfig] class.
 */
final class ProducerNotFoundException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(
            "Producer '{$name}' is not registered. Did you forget #[RegisterProducer] in your MessagingConfig?"
        );
    }
}
