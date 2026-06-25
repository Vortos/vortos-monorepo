<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Fixtures\Demo;

use InvalidArgumentException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver(key: 'loud')]
final class LoudGreeter implements GreeterInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            [GreeterCapability::Shouts->key() => true],
            ['volume' => 11],
        );
    }

    public function greet(string $name): string
    {
        if ($name === '') {
            throw new InvalidArgumentException('Name must not be empty.');
        }

        return "Hello, {$name}.";
    }

    public function greetLoudly(string $name): string
    {
        return strtoupper($this->greet($name));
    }
}
