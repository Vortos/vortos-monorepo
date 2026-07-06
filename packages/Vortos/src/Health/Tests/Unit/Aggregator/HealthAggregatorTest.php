<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Aggregator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Health\Aggregator\HealthAggregator;
use Vortos\Health\Aggregator\HealthBudget;
use Vortos\Health\Aggregator\HealthReport;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\Health\Startup\StartupGate;
use Vortos\Health\Tests\Fixtures\StubProbe;

final class HealthAggregatorTest extends TestCase
{
    // ---- live() ----

    public function testLiveReturnsPassWithNoProbes(): void
    {
        $agg = $this->aggregator([]);

        $report = $agg->live();

        self::assertSame(ProbeStatus::Pass, $report->overallStatus);
        self::assertSame('live', $report->mode);
        self::assertSame([], $report->results);
    }

    public function testLiveReturnsPassWithHealthyLivenessProbe(): void
    {
        $agg = $this->aggregator(['live-check' => StubProbe::liveness('live-check')]);

        $report = $agg->live();

        self::assertSame(ProbeStatus::Pass, $report->overallStatus);
        self::assertCount(1, $report->results);
    }

    public function testLiveIgnoresReadinessProbes(): void
    {
        $agg = $this->aggregator([
            'db' => StubProbe::readiness('db')->withResult(
                ProbeResult::fail('db', ProbeKind::Readiness, 1.0, 'down'),
            ),
        ]);

        $report = $agg->live();

        self::assertSame(ProbeStatus::Pass, $report->overallStatus);
        self::assertCount(0, $report->results);
    }

    public function testLiveIsO1NoInfraProbes(): void
    {
        $slowDb = StubProbe::readiness('db')->withSleep(100);
        $agg = $this->aggregator(['db' => $slowDb]);

        $start = hrtime(true);
        $agg->live();
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertLessThan(50, $elapsed);
    }

    // ---- ready() ----

    public function testReadyPassWithAllHealthy(): void
    {
        $agg = $this->aggregator([
            'db' => StubProbe::readiness('db'),
            'cache' => StubProbe::readiness('cache'),
        ]);

        $report = $agg->ready();

        self::assertSame(ProbeStatus::Pass, $report->overallStatus);
        self::assertSame('ready', $report->mode);
        self::assertCount(2, $report->results);
        self::assertSame(200, $report->httpStatusCode());
    }

    public function testReadyFailsOnCriticalFail(): void
    {
        $agg = $this->aggregator([
            'db' => StubProbe::readiness('db')->withResult(
                ProbeResult::fail('db', ProbeKind::Readiness, 1.0, 'unreachable'),
            ),
            'cache' => StubProbe::readiness('cache'),
        ]);

        $report = $agg->ready();

        self::assertSame(ProbeStatus::Fail, $report->overallStatus);
        self::assertSame(503, $report->httpStatusCode());
    }

    public function testReadyWarnKeepsPassDegradedNotDown(): void
    {
        $agg = $this->aggregator([
            'cache' => StubProbe::readiness('cache')->withResult(
                ProbeResult::warn('cache', ProbeKind::Readiness, 1.0, 'high_latency'),
            ),
        ]);

        $report = $agg->ready();

        self::assertSame(ProbeStatus::Warn, $report->overallStatus);
        self::assertSame(200, $report->httpStatusCode());
    }

    public function testReadyFailOverridesWarn(): void
    {
        $agg = $this->aggregator([
            'db' => StubProbe::readiness('db')->withResult(
                ProbeResult::fail('db', ProbeKind::Readiness, 1.0, 'unreachable'),
            ),
            'cache' => StubProbe::readiness('cache')->withResult(
                ProbeResult::warn('cache', ProbeKind::Readiness, 1.0, 'slow'),
            ),
        ]);

        $report = $agg->ready();

        self::assertSame(ProbeStatus::Fail, $report->overallStatus);
    }

    public function testReadySurfacesSlowest(): void
    {
        $agg = $this->aggregator([
            'fast' => StubProbe::readiness('fast'),
            'slow' => StubProbe::readiness('slow')->withSleep(50),
        ]);

        $report = $agg->ready();

        self::assertNotNull($report->slowest);
        self::assertSame('slow', $report->slowest->name);
    }

    public function testReadyPerProbeDeadlineMarksTimedOut(): void
    {
        $budget = new HealthBudget(perProbeDeadlineMs: 10, overallBudgetMs: 5000);
        $agg = $this->aggregator(
            ['slow' => StubProbe::readiness('slow')->withSleep(50)],
            $budget,
        );

        $report = $agg->ready();

        self::assertCount(1, $report->results);
        self::assertTrue($report->results[0]->timedOut);
        self::assertSame('probe_timeout', $report->results[0]->errorCode);
        self::assertSame(ProbeStatus::Fail, $report->overallStatus);
    }

    public function testReadyOverallBudgetSkipsRemainingProbes(): void
    {
        $budget = new HealthBudget(perProbeDeadlineMs: 20, overallBudgetMs: 20);
        $agg = $this->aggregator([
            'slow' => StubProbe::readiness('slow')->withSleep(50),
            'second' => StubProbe::readiness('second'),
            'third' => StubProbe::readiness('third'),
        ], $budget);

        $report = $agg->ready();

        $skippedResults = array_filter(
            $report->results,
            static fn (ProbeResult $r): bool => $r->skipped,
        );

        self::assertNotEmpty($skippedResults, 'At least one probe should be skipped due to budget exhaustion');

        foreach ($skippedResults as $result) {
            self::assertSame('budget_exhausted', $result->errorCode);
        }
    }

    public function testReadySingleFlightCoalescing(): void
    {
        $callCount = 0;
        $counting = new class ('counter', $callCount) implements \Vortos\Health\Probe\HealthProbeInterface {
            public function __construct(
                private readonly string $n,
                private int &$count,
            ) {}
            public function name(): string { return $this->n; }
            public function kind(): ProbeKind { return ProbeKind::Readiness; }
            public function check(): ProbeResult {
                $this->count++;
                return ProbeResult::pass($this->n, ProbeKind::Readiness, 0.1);
            }
            public function capabilities(): \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor {
                return \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor::create([]);
            }
        };

        $budget = new HealthBudget(3000, 10000, 2000);
        $agg = $this->aggregator(['counter' => $counting], $budget);

        $agg->ready();
        $agg->ready();
        $agg->ready();

        self::assertSame(1, $callCount);
    }

    public function testReadyCacheExpiresAfterTtl(): void
    {
        $callCount = 0;
        $counting = new class ('counter', $callCount) implements \Vortos\Health\Probe\HealthProbeInterface {
            public function __construct(
                private readonly string $n,
                private int &$count,
            ) {}
            public function name(): string { return $this->n; }
            public function kind(): ProbeKind { return ProbeKind::Readiness; }
            public function check(): ProbeResult {
                $this->count++;
                return ProbeResult::pass($this->n, ProbeKind::Readiness, 0.1);
            }
            public function capabilities(): \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor {
                return \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor::create([]);
            }
        };

        $budget = new HealthBudget(3000, 10000, 50);
        $agg = $this->aggregator(['counter' => $counting], $budget);

        $agg->ready();
        usleep(60_000);
        $agg->ready();

        self::assertSame(2, $callCount);
    }

    public function testReadyWithZeroCacheTtlNeverCaches(): void
    {
        $callCount = 0;
        $counting = new class ('counter', $callCount) implements \Vortos\Health\Probe\HealthProbeInterface {
            public function __construct(
                private readonly string $n,
                private int &$count,
            ) {}
            public function name(): string { return $this->n; }
            public function kind(): ProbeKind { return ProbeKind::Readiness; }
            public function check(): ProbeResult {
                $this->count++;
                return ProbeResult::pass($this->n, ProbeKind::Readiness, 0.1);
            }
            public function capabilities(): \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor {
                return \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor::create([]);
            }
        };

        $budget = new HealthBudget(3000, 10000, 0);
        $agg = $this->aggregator(['counter' => $counting], $budget);

        $agg->ready();
        $agg->ready();
        $agg->ready();

        self::assertSame(3, $callCount);
    }

    public function testReadyProbeExceptionYieldsFail(): void
    {
        $agg = $this->aggregator([
            'boom' => StubProbe::readiness('boom')->withException(
                new \RuntimeException('connection refused'),
            ),
        ]);

        $report = $agg->ready();

        self::assertSame(ProbeStatus::Fail, $report->overallStatus);
        self::assertSame('probe_exception', $report->results[0]->errorCode);
        self::assertSame(['exception' => 'RuntimeException'], $report->results[0]->detail);
    }

    public function testReadyPassWithNoProbes(): void
    {
        $agg = $this->aggregator([]);

        $report = $agg->ready();

        self::assertSame(ProbeStatus::Pass, $report->overallStatus);
        self::assertSame([], $report->results);
    }

    // ---- startup() ----

    public function testStartupPassLatchesGate(): void
    {
        $gate = new StartupGate();
        $agg = $this->aggregator(
            ['warmup' => StubProbe::startup('warmup')],
            gate: $gate,
        );

        self::assertFalse($gate->isStarted());

        $report = $agg->startup();

        self::assertSame(ProbeStatus::Pass, $report->overallStatus);
        self::assertSame('startup', $report->mode);
        self::assertTrue($gate->isStarted());
    }

    public function testStartupFailDoesNotLatchGate(): void
    {
        $gate = new StartupGate();
        $agg = $this->aggregator(
            ['warmup' => StubProbe::startup('warmup')->withResult(
                ProbeResult::fail('warmup', ProbeKind::Startup, 1.0, 'not_ready'),
            )],
            gate: $gate,
        );

        $agg->startup();

        self::assertFalse($gate->isStarted());
    }

    public function testStartupSkipsProbesAfterLatch(): void
    {
        $callCount = 0;
        $counting = new class ('warmup', $callCount) implements \Vortos\Health\Probe\HealthProbeInterface {
            public function __construct(
                private readonly string $n,
                private int &$count,
            ) {}
            public function name(): string { return $this->n; }
            public function kind(): ProbeKind { return ProbeKind::Startup; }
            public function check(): ProbeResult {
                $this->count++;
                return ProbeResult::pass($this->n, ProbeKind::Startup, 0.1);
            }
            public function capabilities(): \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor {
                return \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor::create([]);
            }
        };

        $gate = new StartupGate();
        $agg = $this->aggregator(['warmup' => $counting], gate: $gate);

        $agg->startup();
        $agg->startup();
        $agg->startup();

        self::assertSame(1, $callCount);
    }

    // ---- HealthReport ----

    public function testReportTotalLatency(): void
    {
        $agg = $this->aggregator([
            'a' => StubProbe::readiness('a')->withSleep(10),
            'b' => StubProbe::readiness('b')->withSleep(10),
        ]);

        $report = $agg->ready();

        self::assertGreaterThan(15.0, $report->totalLatencyMs);
    }

    public function testReportTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $agg = $this->aggregator(['a' => StubProbe::readiness('a')]);
        $report = $agg->ready();
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $report->timestamp);
        self::assertLessThanOrEqual($after, $report->timestamp);
    }

    public function testReportToPublicArrayIsMinimal(): void
    {
        $agg = $this->aggregator(['a' => StubProbe::readiness('a')]);
        $report = $agg->ready();

        $public = $report->toPublicArray();

        self::assertArrayHasKey('status', $public);
        self::assertArrayHasKey('mode', $public);
        self::assertArrayHasKey('timestamp', $public);
        self::assertCount(3, $public);
    }

    public function testReportToDetailedArrayContainsChecks(): void
    {
        $agg = $this->aggregator([
            'db' => StubProbe::readiness('db'),
        ]);
        $report = $agg->ready();

        $detailed = $report->toDetailedArray();

        self::assertArrayHasKey('checks', $detailed);
        self::assertArrayHasKey('db', $detailed['checks']);
        self::assertArrayHasKey('total_latency_ms', $detailed);
        self::assertArrayHasKey('slowest', $detailed);
    }

    // ---- helpers ----

    /** @param array<string, \Vortos\Health\Probe\HealthProbeInterface> $probes */
    // ---- monitor() (GAP-F) ----

    public function testReadyIgnoresMonitoringProbes(): void
    {
        // A failing monitoring probe (e.g. cert-expiry unreachable from the internal color) must NOT
        // fail readiness — the whole point of GAP-F.
        $agg = $this->aggregator([
            'cert-expiry' => StubProbe::monitoring('cert-expiry')->withResult(
                ProbeResult::fail('cert-expiry', ProbeKind::Monitoring, 1.0, 'cert_near_expiry_critical'),
            ),
        ]);

        $ready = $agg->ready();

        self::assertSame(ProbeStatus::Pass, $ready->overallStatus);
        self::assertCount(0, $ready->results);
    }

    public function testLiveAndStartupIgnoreMonitoringProbes(): void
    {
        $agg = $this->aggregator([
            'cert-expiry' => StubProbe::monitoring('cert-expiry')->withResult(
                ProbeResult::fail('cert-expiry', ProbeKind::Monitoring, 1.0, 'cert_near_expiry_critical'),
            ),
        ]);

        self::assertCount(0, $agg->live()->results);
        self::assertCount(0, $agg->startup()->results);
    }

    public function testMonitorRunsOnlyMonitoringProbes(): void
    {
        $agg = $this->aggregator([
            'cert-expiry' => StubProbe::monitoring('cert-expiry')->withResult(
                ProbeResult::warn('cert-expiry', ProbeKind::Monitoring, 1.0, 'cert_near_expiry', []),
            ),
            'db' => StubProbe::readiness('db'),
        ]);

        $report = $agg->monitor();

        self::assertSame(HealthReport::MONITOR_MODE, $report->mode);
        self::assertCount(1, $report->results);
        self::assertSame('cert-expiry', $report->results[0]->name);
    }

    public function testMonitorReportAlwaysReturnsHttp200EvenWhenFailing(): void
    {
        $agg = $this->aggregator([
            'cert-expiry' => StubProbe::monitoring('cert-expiry')->withResult(
                ProbeResult::fail('cert-expiry', ProbeKind::Monitoring, 1.0, 'cert_near_expiry_critical'),
            ),
        ]);

        $report = $agg->monitor();

        self::assertSame(ProbeStatus::Fail, $report->overallStatus);
        self::assertSame(200, $report->httpStatusCode(), 'monitoring is informational, never a 503 gate');
    }

    private function aggregator(
        array $probes,
        ?HealthBudget $budget = null,
        ?StartupGate $gate = null,
    ): HealthAggregator {
        $locator = new ServiceLocator(
            array_map(
                static fn ($probe) => static fn () => $probe,
                $probes,
            ),
        );

        $registry = new HealthProbeRegistry($locator);

        return new HealthAggregator(
            $registry,
            $budget ?? new HealthBudget(),
            $gate ?? new StartupGate(),
        );
    }
}
