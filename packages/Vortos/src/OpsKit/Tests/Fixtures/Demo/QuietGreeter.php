<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use InvalidArgumentException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

#[AsDriver(key: 'quiet')]
final class QuietGreeter implements GreeterInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            [GreeterCapability::Shouts->key() => false],
            ['volume' => 2],
        );
    }

    public function greet(string $name): string
    {
        if ($name === '') {
            throw new InvalidArgumentException('Name must not be empty.');
        }

        return "hello, {$name}.";
    }

    public function greetLoudly(string $name): string
    {
        // Honest refusal — never a silent no-op. This is what the TCK asserts.
        throw UnsupportedCapabilityException::for('quiet', GreeterCapability::Shouts);
    }
}
