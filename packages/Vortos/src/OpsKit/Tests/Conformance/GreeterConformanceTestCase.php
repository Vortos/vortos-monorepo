<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Conformance;

use InvalidArgumentException;
use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\OpsKit\Tests\Fixtures\Demo\GreeterCapability;
use Vortos\OpsKit\Tests\Fixtures\Demo\GreeterInterface;

/**
 * The demo concern's TCK — exactly what every real port ships: it extends the kit's
 * universal {@see ConformanceTestCase} and adds the port-specific contract, including
 * the mandatory negative cases (malformed input rejected; an unsupported operation
 * throws rather than silently no-ops). Both demo drivers extend this and so are held
 * to the same contract — proving the kit works for capability-honest and
 * capability-limited drivers alike.
 */
abstract class GreeterConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createDriver(): DriverInterface;

    private function greeter(): GreeterInterface
    {
        $driver = $this->createDriver();
        self::assertInstanceOf(GreeterInterface::class, $driver);

        return $driver;
    }

    final public function test_greet_includes_the_name(): void
    {
        $this->assertStringContainsString('World', $this->greeter()->greet('World'));
    }

    final public function test_rejects_malformed_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->greeter()->greet('');
    }

    final public function test_loud_greeting_honors_declared_capability(): void
    {
        $greeter = $this->greeter();
        $supportsShouting = $greeter->capabilities()->supports(GreeterCapability::Shouts);

        if ($supportsShouting) {
            $this->assertSame('HELLO, WORLD.', $greeter->greetLoudly('World'));
        } else {
            // Capability-limited driver MUST refuse honestly, not pretend.
            $this->assertHonestlyUnsupported($greeter->capabilities(), GreeterCapability::Shouts);
            $this->assertRejectsUnsupportedCapability(static fn () => $greeter->greetLoudly('World'));
        }
    }
}
