<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Cutover\ReconcileResult;
use Vortos\Deploy\Driver\Caddy\CaddyAdminClient;
use Vortos\Deploy\Driver\Caddy\CaddyConfigFragment;
use Vortos\Deploy\Driver\Caddy\CaddyEdgeRouter;
use Vortos\Deploy\Driver\Caddy\DrainObserver;
use Vortos\Deploy\Driver\Caddy\MountedConfigWriter;
use Vortos\Deploy\Exception\CutoverFailedException;
use Vortos\Deploy\Exception\CutoverRevertedException;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Exercises {@see CutoverCoordinator} end-to-end against the real Caddy driver — not
 * fakes — for the 3 scenarios the Block 9 plan (§8, §15.2) calls for:
 *
 *  1. A real cutover, with zero-drop verified by {@see DrainObserver} against Caddy's
 *     own metrics endpoint.
 *  2. Reconcile-after-restart: a brand-new {@see CaddyEdgeRouter} instance (no
 *     in-memory cache) must re-derive live state purely from Caddy's own config —
 *     proving state survives a process restart because Caddy, not the process, is the
 *     source of truth.
 *  3. A health-gate failure mid-cutover triggers {@see CutoverCoordinator}'s real
 *     revert path, verified by checking Caddy's actual config afterwards rather than
 *     trusting the return value alone.
 *
 * Needs a live Caddy admin API; follows the same `markTestSkipped()`-on-unreachable
 * convention as {@see CaddyEdgeRouterConformanceTest}, with the same reachability-once
 * caching to avoid paying a DNS-resolution-failure cost per test.
 */
final class CutoverIntegrationTest extends TestCase
{
    private static ?bool $caddyReachable = null;

    private CaddyAdminClient $adminClient;

    protected function setUp(): void
    {
        $adminBaseUrl = $_ENV['CADDY_ADMIN_URL'] ?? 'http://caddy:2019';
        $httpClient = new Client(['connect_timeout' => 2, 'timeout' => 5]);
        $this->adminClient = new CaddyAdminClient($httpClient, new HttpFactory(), $adminBaseUrl);

        if (self::$caddyReachable === null) {
            try {
                $this->adminClient->currentConfig();
                self::$caddyReachable = true;
            } catch (Throwable) {
                self::$caddyReachable = false;
            }
        }

        if (self::$caddyReachable === false) {
            $this->markTestSkipped('Caddy admin API not reachable at ' . $adminBaseUrl);
        }
    }

    private function realRouter(): CaddyEdgeRouter
    {
        $adminBaseUrl = $_ENV['CADDY_ADMIN_URL'] ?? 'http://caddy:2019';
        $adminListen = ltrim((string) preg_replace('#^https?://#', '', $adminBaseUrl), '/');

        return new CaddyEdgeRouter(
            $this->adminClient,
            new CaddyConfigFragment($adminListen),
            new MountedConfigWriter(sys_get_temp_dir() . '/caddy-cutover-integration-upstream.json'),
            new DrainObserver($this->adminClient),
        );
    }

    public function test_real_cutover_verifies_zero_drop(): void
    {
        $store = new FakeDeployStateStore();
        $coordinator = new CutoverCoordinator($this->realRouter(), $store, new NullCutoverEventRecorder());

        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            drainDeadlineSeconds: 2,
        );

        $result = $coordinator->cutover(
            $desired,
            imageDigest: 'sha256:' . str_repeat('a', 64),
            buildId: 'build-1',
            planHash: 'plan-hash-1',
            previousEndpoint: new ColorEndpoint('app-green', 8082),
        );

        self::assertTrue($result->succeeded);
        self::assertTrue($result->verifiedLiveUpstream);
        self::assertSame(0, $result->forciblyClosed, 'No real traffic was flowing — drain must be zero-drop.');

        $release = $store->currentRelease('production');
        self::assertNotNull($release);
        self::assertSame(ActiveColor::Blue, $release->activeColor);
        self::assertSame(1, $release->generation);
    }

    public function test_reconcile_survives_simulated_process_restart(): void
    {
        $store = new FakeDeployStateStore();
        $router1 = $this->realRouter();
        $coordinator = new CutoverCoordinator($router1, $store, new NullCutoverEventRecorder());

        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-green', 8082),
            drainDeadlineSeconds: 2,
        );

        $coordinator->cutover(
            $desired,
            imageDigest: 'sha256:' . str_repeat('b', 64),
            buildId: 'build-2',
            planHash: 'plan-hash-2',
            previousEndpoint: new ColorEndpoint('app-blue', 8081),
        );

        // A fresh instance has no in-memory $lastLiveRoute — it must hit Caddy's /config/.
        $router2 = $this->realRouter();
        $live = $router2->liveRoute();

        self::assertNotNull($live, 'Live route must be reconstructable from Caddy config alone after a restart.');
        self::assertSame(ActiveColor::Green, $live->activeColor);
        self::assertSame('app-green', $live->upstreamHost);

        $reconcileResult = $router2->reconcile($desired);
        self::assertTrue($reconcileResult->inSync);
        self::assertFalse($reconcileResult->drifted);
    }

    public function test_health_gate_failure_triggers_real_revert(): void
    {
        $store = new FakeDeployStateStore();
        $realRouter = $this->realRouter();
        $baselineCoordinator = new CutoverCoordinator($realRouter, $store, new NullCutoverEventRecorder());

        // Establish a known-good baseline to revert back to.
        $good = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            drainDeadlineSeconds: 2,
        );

        $baselineCoordinator->cutover(
            $good,
            imageDigest: 'sha256:' . str_repeat('c', 64),
            buildId: 'build-3',
            planHash: 'plan-hash-3',
            previousEndpoint: new ColorEndpoint('app-green', 8082),
        );

        // Fails verification on the *next* call only (simulating a health-gate failure
        // mid-cutover); the coordinator's own revert call still goes through the real
        // Caddy driver underneath.
        $failOnceRouter = new class($realRouter) implements EdgeRouterInterface {
            private int $calls = 0;

            public function __construct(private readonly EdgeRouterInterface $inner)
            {
            }

            public function cutover(DesiredRoute $desired): CutoverResult
            {
                ++$this->calls;

                if ($this->calls === 1) {
                    throw CutoverFailedException::verifyMismatch('app-bad:9999', 'unreachable');
                }

                return $this->inner->cutover($desired);
            }

            public function liveRoute(): ?LiveRoute
            {
                return $this->inner->liveRoute();
            }

            public function reconcile(DesiredRoute $desired): ReconcileResult
            {
                return $this->inner->reconcile($desired);
            }

            public function capabilities(): CapabilityDescriptor
            {
                return $this->inner->capabilities();
            }
        };

        $failingCoordinator = new CutoverCoordinator($failOnceRouter, $store, new NullCutoverEventRecorder());

        $bad = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-bad', 9999),
            drainDeadlineSeconds: 2,
        );

        $this->expectException(CutoverRevertedException::class);

        try {
            $failingCoordinator->cutover(
                $bad,
                imageDigest: 'sha256:' . str_repeat('d', 64),
                buildId: 'build-4',
                planHash: 'plan-hash-4',
                previousEndpoint: new ColorEndpoint('app-blue', 8081),
            );
        } finally {
            // Real Caddy must be back on the last known-good route, not stuck on the
            // broken one or left in a half-applied state.
            $live = $realRouter->liveRoute();
            self::assertSame(ActiveColor::Blue, $live?->activeColor);
        }
    }
}
