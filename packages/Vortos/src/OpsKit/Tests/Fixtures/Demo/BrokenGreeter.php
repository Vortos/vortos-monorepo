<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * A deliberately mis-declared driver: it implements the port but carries NO
 * #[AsDriver] attribute. Used to prove the collecting pass fails closed at compile
 * time with a clear "add #[AsDriver]" message.
 */
final class BrokenGreeter implements GreeterInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([GreeterCapability::Shouts->key() => false]);
    }

    public function greet(string $name): string
    {
        return "hi {$name}";
    }

    public function greetLoudly(string $name): string
    {
        return "HI {$name}";
    }
}
