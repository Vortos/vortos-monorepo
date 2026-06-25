<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;
use Vortos\Health\Probe\Bridge\LegacyHealthCheckProbe;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeStatus;

final class LegacyHealthCheckProbeTest extends TestCase
{
    public function testHealthyLegacyCheckYieldsPass(): void
    {
        $legacy = $this->createLegacyCheck(healthy: true);
        $probe = new LegacyHealthCheckProbe($legacy, 'legacy-test', true);

        $result = $probe->check();

        self::assertSame(ProbeStatus::Pass, $result->status);
        self::assertSame('test-check', $result->name);
    }

    public function testUnhealthyCriticalLegacyCheckYieldsFail(): void
    {
        $legacy = $this->createLegacyCheck(healthy: false, error: 'connection refused');
        $probe = new LegacyHealthCheckProbe($legacy, 'legacy-test', true);

        $result = $probe->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('legacy_check_failed', $result->errorCode);
    }

    public function testUnhealthyNonCriticalLegacyCheckYieldsWarn(): void
    {
        $legacy = $this->createLegacyCheck(healthy: false, error: 'slow');
        $probe = new LegacyHealthCheckProbe($legacy, 'legacy-test', false);

        $result = $probe->check();

        self::assertSame(ProbeStatus::Warn, $result->status);
        self::assertSame('legacy_check_degraded', $result->errorCode);
    }

    public function testLegacyProbeIsReadinessKind(): void
    {
        $legacy = $this->createLegacyCheck(healthy: true);
        $probe = new LegacyHealthCheckProbe($legacy, 'legacy-test', true);

        self::assertSame(ProbeKind::Readiness, $probe->kind());
    }

    public function testCapabilitiesDeclareDependencyCheck(): void
    {
        $legacy = $this->createLegacyCheck(healthy: true);
        $probe = new LegacyHealthCheckProbe($legacy, 'legacy-test', true);

        self::assertTrue($probe->capabilities()->supports(HealthCapability::DependencyCheck));
    }

    public function testLegacyErrorCodePreserved(): void
    {
        $legacy = $this->createLegacyCheck(healthy: false, error: 'down', errorCode: 'redis_unreachable');
        $probe = new LegacyHealthCheckProbe($legacy, 'legacy-test', true);

        $result = $probe->check();

        self::assertSame('redis_unreachable', $result->errorCode);
    }

    private function createLegacyCheck(bool $healthy, ?string $error = null, ?string $errorCode = null): HealthCheckInterface
    {
        return new class ($healthy, $error, $errorCode) implements HealthCheckInterface {
            public function __construct(
                private readonly bool $healthy,
                private readonly ?string $error,
                private readonly ?string $errorCode,
            ) {}

            public function name(): string
            {
                return 'test-check';
            }

            public function check(): HealthResult
            {
                return new HealthResult(
                    $this->name(),
                    $this->healthy,
                    1.5,
                    $this->error,
                    $this->errorCode,
                );
            }
        };
    }
}
