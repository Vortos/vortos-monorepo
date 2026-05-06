<?php

declare(strict_types=1);

namespace Vortos\Tests\Foundation\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Foundation\Health\HealthRegistry;
use Vortos\Foundation\Health\Http\HealthController;

final class HealthControllerTest extends TestCase
{
    public function test_public_health_response_is_scrubbed_and_optional_failure_does_not_return_503(): void
    {
        $controller = new HealthController(
            new HealthRegistry([
                ['check' => new ControllerStubHealthCheck('database', true), 'critical' => true],
                ['check' => new ControllerStubHealthCheck('kafka', false), 'critical' => false],
            ]),
            new HealthDetailPolicy(policy: HealthDetailPolicy::TOKEN, token: 'secret'),
        );

        $response = $controller(new Request());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('health', $payload['mode']);
        $this->assertSame(['status' => 'degraded'], $payload['checks']['kafka']);
        $this->assertArrayNotHasKey('latency_ms', $payload['checks']['database']);
    }

    public function test_detailed_health_response_redacts_raw_errors_in_prod(): void
    {
        $controller = new HealthController(
            new HealthRegistry([
                ['check' => new ControllerStubHealthCheck('database', false), 'critical' => true],
            ]),
            new HealthDetailPolicy(policy: HealthDetailPolicy::ALWAYS, appEnv: 'prod'),
        );

        $response = $controller(new Request());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('health_check_failed', $payload['checks']['database']['error_code']);
        $this->assertArrayNotHasKey('error', $payload['checks']['database']);
    }

    public function test_ready_runs_only_critical_checks(): void
    {
        $controller = new HealthController(
            new HealthRegistry([
                ['check' => new ControllerStubHealthCheck('database', true), 'critical' => true],
                ['check' => new ControllerStubHealthCheck('kafka', false), 'critical' => false],
            ]),
            new HealthDetailPolicy(policy: HealthDetailPolicy::ALWAYS, appEnv: 'dev'),
        );

        $response = $controller->ready(new Request());
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('ready', $payload['mode']);
        $this->assertSame(['database'], array_keys($payload['checks']));
    }

    public function test_live_does_not_run_dependency_checks(): void
    {
        $controller = new HealthController(
            new HealthRegistry([
                ['check' => new ControllerStubHealthCheck('database', false), 'critical' => true],
            ]),
            new HealthDetailPolicy(policy: HealthDetailPolicy::ALWAYS),
        );

        $response = $controller->live();
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('live', $payload['mode']);
        $this->assertArrayNotHasKey('checks', $payload);
    }
}

final class ControllerStubHealthCheck implements \Vortos\Foundation\Health\Contract\HealthCheckInterface
{
    public function __construct(
        private readonly string $name,
        private readonly bool $healthy,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function check(): \Vortos\Foundation\Health\HealthResult
    {
        return new \Vortos\Foundation\Health\HealthResult($this->name, $this->healthy, 1.0, $this->healthy ? null : 'failed');
    }
}
