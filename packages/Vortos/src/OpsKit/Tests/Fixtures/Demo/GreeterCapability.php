<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * Capability keys for the throwaway demo "greeter" concern used to prove the kit.
 */
enum GreeterCapability: string implements CapabilityKey
{
    case Shouts = 'shouts';

    public function key(): string
    {
        return $this->value;
    }
}
