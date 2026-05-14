<?php

declare(strict_types=1);

namespace Vortos\Tests\Foundation\Health;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Health\HealthResult;

final class HealthRegistryTest extends TestCase
{
    public function test_optional_degraded_check_does_not_make_critical_health_fail(): void
    {
        $registry = new HealthRegistry([
            ['check' => new StubHealthCheck('database', true), 'critical' => true],
            ['check' => new StubHealthCheck('kafka', false), 'critical' => false],
        ]);

        $results = $registry->run();

        $this->assertTrue($registry->isHealthy($results));
        $this->assertFalse($registry->isHealthy($results, criticalOnly: false));
        $this->assertFalse($results['kafka']->critical);
    }

    public function test_ready_mode_runs_only_critical_checks(): void
    {
        $registry = new HealthRegistry([
            ['check' => new StubHealthCheck('database', true), 'critical' => true],
            ['check' => new StubHealthCheck('kafka', false), 'critical' => false],
        ]);

        $this->assertSame(['database'], array_keys($registry->run(criticalOnly: true)));
    }

    public function test_timeout_marks_check_degraded(): void
    {
        $registry = new HealthRegistry([
            ['check' => new StubHealthCheck('database', true, 20.0), 'critical' => true, 'timeout_ms' => 10],
        ]);

        $result = $registry->run()['database'];

        $this->assertFalse($result->healthy);
        $this->assertTrue($result->timedOut);
        $this->assertSame('health_check_timeout', $result->errorCode);
    }
}

final class StubHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly string $name,
        private readonly bool $healthy,
        private readonly float $latencyMs = 1.0,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthResult
    {
        // Sleep so the external wall-clock measurement in HealthRegistry matches the declared latency
        usleep((int) ($this->latencyMs * 1000));
        return new HealthResult($this->name, $this->healthy, $this->latencyMs, $this->healthy ? null : 'failed');
    }
}
