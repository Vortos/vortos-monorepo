<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;
use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

/**
 * The conformance TCK base (roadmap §10.4).
 *
 * Every port ships a concrete subclass adding its port-specific contract; a concrete
 * *driver* test then extends that and supplies a driver instance. This base asserts
 * the universal contract that holds for every driver of every concern:
 *
 *  - it is a {@see DriverInterface} and declares a well-formed key;
 *  - it returns a {@see CapabilityDescriptor} that is self-consistent and round-trips
 *    through its canonical serialization byte-stably (so capability diffs are stable);
 *  - **negative cases** (the anti-drift core): the helpers below let a subclass assert
 *    that an unsupported operation raises {@see UnsupportedCapabilityException} (never
 *    a silent no-op) and that the descriptor reports unsupported capabilities honestly.
 *
 * Subclasses implement {@see createDriver()} and {@see expectedKey()}.
 */
abstract class ConformanceTestCase extends TestCase
{
    abstract protected function createDriver(): DriverInterface;

    /** The key the driver is registered under (its #[AsDriver] key). */
    abstract protected function expectedKey(): string;

    final public function test_driver_is_a_driver_instance(): void
    {
        $this->assertInstanceOf(DriverInterface::class, $this->createDriver());
    }

    final public function test_driver_declares_a_well_formed_key(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[a-z][a-z0-9-]*$/',
            $this->expectedKey(),
            'Driver keys must be lower-kebab (^[a-z][a-z0-9-]*$).',
        );
    }

    final public function test_driver_reports_a_capability_descriptor(): void
    {
        $this->assertInstanceOf(CapabilityDescriptor::class, $this->createDriver()->capabilities());
    }

    final public function test_capabilities_are_pure(): void
    {
        $driver = $this->createDriver();
        $this->assertEquals(
            $driver->capabilities()->toArray(),
            $driver->capabilities()->toArray(),
            'capabilities() must be side-effect-free and return a stable descriptor.',
        );
    }

    final public function test_capability_descriptor_round_trips_canonically(): void
    {
        $descriptor = $this->createDriver()->capabilities();
        $serialized = $descriptor->toArray();

        $this->assertSame(
            $serialized,
            CapabilityDescriptor::fromArray($serialized)->toArray(),
            'A descriptor must survive toArray()/fromArray() byte-for-byte (canonical, stable serialization).',
        );
    }

    final public function test_supports_is_consistent_with_serialized_capabilities(): void
    {
        $descriptor = $this->createDriver()->capabilities();
        foreach ($descriptor->toArray()['capabilities'] as $key => $supported) {
            $this->assertSame(
                $supported,
                $descriptor->supports($key),
                "supports('{$key}') must agree with the serialized capability map.",
            );
        }
    }

    /**
     * Assert that performing an operation the driver does not support raises the
     * typed {@see UnsupportedCapabilityException} — proving honesty, not a no-op.
     */
    final protected function assertRejectsUnsupportedCapability(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected UnsupportedCapabilityException for an unsupported operation; none thrown (silent no-op?).');
        } catch (UnsupportedCapabilityException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /**
     * Assert the descriptor honestly reports a capability it does not support as false.
     */
    final protected function assertHonestlyUnsupported(CapabilityDescriptor $descriptor, CapabilityKey|string $capability): void
    {
        $this->assertFalse(
            $descriptor->supports($capability),
            'A driver must report unsupported capabilities as false, never silently true.',
        );
    }
}
