<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Registry\Exception;

use RuntimeException;

/**
 * Thrown when a transport name cannot be resolved in the TransportRegistry.
 * This is a programming error — all transports must be registered at compile time
 * via #[RegisterTransport] in a #[MessagingConfig] class.
 */
final class TransportNotFoundException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(
            "Transport '{$name}' is not registered. Did you forget #[RegisterTransport] in your MessagingConfig?"
        );
    }
}
