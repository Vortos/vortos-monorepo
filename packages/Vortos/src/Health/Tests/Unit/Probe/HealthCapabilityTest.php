<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;

final class HealthCapabilityTest extends TestCase
{
    public function testImplementsCapabilityKey(): void
    {
        foreach (HealthCapability::cases() as $cap) {
            self::assertInstanceOf(CapabilityKey::class, $cap);
        }
    }

    public function testKeyReturnsValue(): void
    {
        self::assertSame('dependency_check', HealthCapability::DependencyCheck->key());
        self::assertSame('bounded_latency', HealthCapability::BoundedLatency->key());
        self::assertSame('read_only', HealthCapability::ReadOnly->key());
        self::assertSame('process_local', HealthCapability::ProcessLocal->key());
    }

    public function testCaseCount(): void
    {
        self::assertCount(4, HealthCapability::cases());
    }
}
