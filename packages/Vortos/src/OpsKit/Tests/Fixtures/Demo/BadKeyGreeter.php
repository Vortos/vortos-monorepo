<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * A driver whose #[AsDriver] key is NOT lower-kebab — used to prove the collecting
 * pass rejects malformed keys at compile time.
 */
#[AsDriver(key: 'Loud_Bad')]
final class BadKeyGreeter implements GreeterInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([GreeterCapability::Shouts->key() => false]);
    }

    public function greet(string $name): string
    {
        return "x {$name}";
    }

    public function greetLoudly(string $name): string
    {
        return "X {$name}";
    }
}
