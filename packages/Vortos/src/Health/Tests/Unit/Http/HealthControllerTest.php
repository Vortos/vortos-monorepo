<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Health\Aggregator\HealthAggregator;
use Vortos\Health\Aggregator\HealthBudget;
use Vortos\Health\Http\HealthController;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Startup\StartupGate;
use Vortos\Health\Tests\Fixtures\StubProbe;
use Vortos\Http\Request;

final class HealthControllerTest extends TestCase
{
    public function testLiveReturns200WithNoStoreHeader(): void
    {
        $ctrl = $this->controller([]);
        $response = $ctrl->live();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));

        $data = json_decode($response->getContent(), true);
        self::assertSame('pass', $data['status']);
        self::assertSame('live', $data['mode']);
    }

    public function testReadyReturns200WhenHealthy(): void
    {
        $ctrl = $this->controller(['db' => StubProbe::readiness('db')]);
        $request = Request::create('/health/ready');
        $response = $ctrl->ready($request);

        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('pass', $data['status']);
    }

    public function testReadyReturns503WhenUnhealthy(): void
    {
        $ctrl = $this->controller([
            'db' => StubProbe::readiness('db')->withResult(
                ProbeResult::fail('db', ProbeKind::Readiness, 1.0, 'unreachable'),
            ),
        ]);
        $request = Request::create('/health/ready');
        $response = $ctrl->ready($request);

        self::assertSame(503, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        self::assertSame('fail', $data['status']);
    }

    public function testStartupReturns200WhenHealthy(): void
    {
        $ctrl = $this->controller(['warmup' => StubProbe::startup('warmup')]);
        $request = Request::create('/health/startup');
        $response = $ctrl->startup($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPublicResponseOmitsChecksInProdMode(): void
    {
        $policy = new HealthDetailPolicy(
            policy: HealthDetailPolicy::NEVER,
            appEnv: 'prod',
            appDebug: false,
        );
        $ctrl = $this->controller(['db' => StubProbe::readiness('db')], $policy);
        $request = Request::create('/health/ready');
        $response = $ctrl->ready($request);

        $data = json_decode($response->getContent(), true);

        self::assertArrayHasKey('status', $data);
        self::assertArrayHasKey('mode', $data);
        self::assertArrayNotHasKey('checks', $data);
    }

    public function testDetailedResponseIncludesChecksInDebugMode(): void
    {
        $policy = new HealthDetailPolicy(
            policy: HealthDetailPolicy::ALWAYS,
            appEnv: 'dev',
            appDebug: true,
            exposeRawErrors: true,
        );
        $ctrl = $this->controller(['db' => StubProbe::readiness('db')], $policy);
        $request = Request::create('/health/ready');
        $response = $ctrl->ready($request);

        $data = json_decode($response->getContent(), true);

        self::assertArrayHasKey('checks', $data);
        self::assertArrayHasKey('db', $data['checks']);
        self::assertArrayHasKey('total_latency_ms', $data);
    }

    public function testMonitorReturns200EvenWhenMonitoringProbeFails(): void
    {
        // GAP-F: /health/monitor is informational — a cert-near-expiry breach reports fail in the body
        // but the endpoint must never 503 (an orchestrator could mistake that for unreadiness).
        $ctrl = $this->controller([
            'cert-expiry' => StubProbe::monitoring('cert-expiry')->withResult(
                ProbeResult::fail('cert-expiry', ProbeKind::Monitoring, 1.0, 'cert_near_expiry_critical'),
            ),
        ]);
        $request = Request::create('/health/monitor');
        $response = $ctrl->monitor($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $data = json_decode($response->getContent(), true);
        self::assertSame('fail', $data['status']);
        self::assertSame('monitor', $data['mode']);
        self::assertArrayHasKey('cert-expiry', $data['checks']);
    }

    public function testReadyStays200WhenOnlyMonitoringProbeFails(): void
    {
        // The cert probe is monitoring-kind, so it is invisible to the readiness gate.
        $ctrl = $this->controller([
            'cert-expiry' => StubProbe::monitoring('cert-expiry')->withResult(
                ProbeResult::fail('cert-expiry', ProbeKind::Monitoring, 1.0, 'cert_near_expiry_critical'),
            ),
        ]);
        $request = Request::create('/health/ready');
        $response = $ctrl->ready($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pass', json_decode($response->getContent(), true)['status']);
    }

    public function testIndexRouteDelegatesToReady(): void
    {
        $ctrl = $this->controller(['db' => StubProbe::readiness('db')]);
        $request = Request::create('/health');
        $response = $ctrl->index($request);

        $data = json_decode($response->getContent(), true);
        self::assertSame('ready', $data['mode']);
    }

    public function testResponseNeverEchoesRequestInput(): void
    {
        $ctrl = $this->controller(['db' => StubProbe::readiness('db')]);
        $request = Request::create('/health/ready?inject=<script>alert(1)</script>');
        $response = $ctrl->ready($request);

        self::assertStringNotContainsString('<script>', $response->getContent());
        self::assertStringNotContainsString('inject', $response->getContent());
    }

    public function testAllEndpointsSetNoCacheHeaders(): void
    {
        $ctrl = $this->controller(['db' => StubProbe::readiness('db')]);

        foreach (['live', 'ready', 'startup'] as $method) {
            if ($method === 'live') {
                $response = $ctrl->live();
            } else {
                $request = Request::create("/health/{$method}");
                $response = $ctrl->$method($request);
            }

            self::assertStringContainsString(
                'no-store',
                $response->headers->get('Cache-Control'),
                "Cache-Control no-store missing on /{$method}",
            );
        }
    }

    /** @param array<string, HealthProbeInterface> $probes */
    private function controller(array $probes, ?HealthDetailPolicy $policy = null): HealthController
    {
        $locator = new ServiceLocator(
            array_map(
                static fn ($probe) => static fn () => $probe,
                $probes,
            ),
        );
        $registry = new HealthProbeRegistry($locator);
        $aggregator = new HealthAggregator(
            $registry,
            new HealthBudget(),
            new StartupGate(),
        );

        return new HealthController(
            $aggregator,
            $policy ?? new HealthDetailPolicy(
                policy: HealthDetailPolicy::ALWAYS,
                appEnv: 'dev',
                appDebug: true,
                exposeRawErrors: true,
            ),
        );
    }
}
