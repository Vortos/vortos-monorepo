<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\Capability\UptimeCapability;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;

final class UptimeCapabilityTest extends TestCase
{
    public function testImplementsCapabilityKey(): void
    {
        foreach (UptimeCapability::cases() as $cap) {
            self::assertInstanceOf(CapabilityKey::class, $cap);
        }
    }

    public function testKeyReturnsValue(): void
    {
        self::assertSame('synthetic_journey', UptimeCapability::SyntheticJourney->key());
        self::assertSame('multi_region', UptimeCapability::MultiRegion->key());
        self::assertSame('incident_api', UptimeCapability::IncidentApi->key());
        self::assertSame('response_time_slo', UptimeCapability::ResponseTimeSlo->key());
    }

    public function testCaseCount(): void
    {
        self::assertCount(4, UptimeCapability::cases());
    }
}
