<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Capability;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Capability\RequiredCapabilities;
use Vortos\OpsKit\Tests\Fixtures\Demo\GreeterCapability;

final class CapabilityDescriptorTest extends TestCase
{
    public function test_supports_reads_declared_capabilities(): void
    {
        $descriptor = CapabilityDescriptor::create([
            'rolling_across_nodes' => false,
            'zero_downtime' => true,
        ]);

        $this->assertTrue($descriptor->supports('zero_downtime'));
        $this->assertFalse($descriptor->supports('rolling_across_nodes'));
    }

    public function test_unknown_capability_is_unsupported_not_an_error(): void
    {
        $descriptor = CapabilityDescriptor::create(['a' => true]);
        $this->assertFalse($descriptor->supports('never_declared'));
    }

    public function test_accepts_capability_key_enum(): void
    {
        $descriptor = CapabilityDescriptor::create([GreeterCapability::Shouts->key() => true]);
        $this->assertTrue($descriptor->supports(GreeterCapability::Shouts));
    }

    public function test_constraint_returns_value_or_null(): void
    {
        $descriptor = CapabilityDescriptor::create([], ['max_nodes' => 1]);
        $this->assertSame(1, $descriptor->constraint('max_nodes'));
        $this->assertNull($descriptor->constraint('absent'));
    }

    public function test_non_bool_capability_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CapabilityDescriptor::create(['x' => 'yes']);
    }

    public function test_non_scalar_constraint_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CapabilityDescriptor::create([], ['bad' => ['array']]);
    }

    public function test_zero_schema_version_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CapabilityDescriptor::create([], [], 0);
    }

    public function test_to_array_is_canonical_sorted(): void
    {
        $descriptor = CapabilityDescriptor::create(
            ['zeta' => true, 'alpha' => false],
            ['z' => 1, 'a' => 2],
        );

        $array = $descriptor->toArray();
        $this->assertSame(['alpha', 'zeta'], array_keys($array['capabilities']));
        $this->assertSame(['a', 'z'], array_keys($array['constraints']));
        $this->assertSame(1, $array['schemaVersion']);
    }

    public function test_round_trips_byte_stably(): void
    {
        $descriptor = CapabilityDescriptor::create(
            ['b' => true, 'a' => false],
            ['k' => 'v'],
            3,
        );

        $serialized = $descriptor->toArray();
        $this->assertSame($serialized, CapabilityDescriptor::fromArray($serialized)->toArray());
    }

    public function test_pinned_serialization_vector_is_stable(): void
    {
        // A hashing/serialization change here would silently re-classify capability
        // diffs — pin the exact shape so that can never happen unnoticed.
        $descriptor = CapabilityDescriptor::create(
            ['zero_downtime' => true, 'rolling_across_nodes' => false],
            ['max_nodes' => 1],
        );

        $this->assertSame(
            [
                'schemaVersion' => 1,
                'capabilities' => [
                    'rolling_across_nodes' => false,
                    'zero_downtime' => true,
                ],
                'constraints' => [
                    'max_nodes' => 1,
                ],
            ],
            $descriptor->toArray(),
        );
    }

    public function test_satisfies_returns_null_when_all_requirements_met(): void
    {
        $descriptor = CapabilityDescriptor::create(['a' => true, 'b' => true], ['n' => 3]);
        $required = RequiredCapabilities::of(['a', 'b'], ['n' => 3]);

        $this->assertNull($descriptor->satisfies($required));
    }

    public function test_satisfies_reports_missing_capability(): void
    {
        $descriptor = CapabilityDescriptor::create(['a' => true, 'b' => false]);
        $required = RequiredCapabilities::of(['a', 'b']);

        $mismatch = $descriptor->satisfies($required);
        $this->assertNotNull($mismatch);
        $this->assertSame(['b'], $mismatch->missingCapabilities);
    }

    public function test_satisfies_reports_constraint_violation_including_undeclared(): void
    {
        $descriptor = CapabilityDescriptor::create(['a' => true], ['n' => 1]);
        $required = RequiredCapabilities::of(['a'], ['n' => 5, 'missing' => 'x']);

        $mismatch = $descriptor->satisfies($required);
        $this->assertNotNull($mismatch);
        $this->assertSame(['required' => 5, 'actual' => 1], $mismatch->constraintViolations['n']);
        $this->assertSame(['required' => 'x', 'actual' => null], $mismatch->constraintViolations['missing']);
    }
}
