<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * A throwaway demo port: it extends DriverInterface (so every greeter declares its
 * capabilities), exposes a universal operation (greet) and a capability-gated one
 * (greetLoudly, which requires the Shouts capability). This is enough to exercise
 * the whole swappability contract — registry, capabilities, and the TCK's negative
 * cases — without any real concern.
 */
interface GreeterInterface extends DriverInterface
{
    /** @throws \InvalidArgumentException on empty input (malformed-input contract) */
    public function greet(string $name): string;

    /**
     * @throws \Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException
     *         when the driver does not support the Shouts capability
     */
    public function greetLoudly(string $name): string;
}
