<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Cutover\EdgeReconciler;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Cutover\ReconcileRateLimiter;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter;
use Vortos\Deploy\Tests\Fixtures\InMemoryRateLimitStateStore;

final class EdgeReconcilerTest extends TestCase
{
    private FakeEdgeRouter $router;
    private FakeDeployStateStore $store;
    private ReconcileRateLimiter $limiter;
    private EdgeReconciler $reconciler;

    protected function setUp(): void
    {
        $this->router = new FakeEdgeRouter();
        $this->store = new FakeDeployStateStore();
        $this->limiter = new ReconcileRateLimiter(new InMemoryRateLimitStateStore(), minIntervalSeconds: 10);
        $this->reconciler = new EdgeReconciler(
            $this->router,
            $this->store,
            new ComposeProjectFactory(),
            $this->limiter,
            new NullCutoverEventRecorder(),
        );
    }

    public function test_no_release_recorded_returns_in_sync(): void
    {
        $result = $this->reconciler->reconcile('production');

        $this->assertTrue($result->inSync);
        $this->assertSame('no release recorded', $result->detail);
    }

    public function test_in_sync_is_noop(): void
    {
        $this->recordRelease(ActiveColor::Blue, 1);
        $this->router->setLiveRoute(new LiveRoute(ActiveColor::Blue, 'app-blue', 8081, 100));

        $result = $this->reconciler->reconcile('production');

        $this->assertTrue($result->inSync);
        $this->assertEmpty($this->router->cutoverHistory());
    }

    public function test_drift_corrects_to_desired(): void
    {
        $this->recordRelease(ActiveColor::Blue, 1);
        $this->router->setLiveRoute(new LiveRoute(ActiveColor::Green, 'app-green', 8082, 100));

        $result = $this->reconciler->reconcile('production');

        $this->assertTrue($result->drifted);
        $this->assertTrue($result->corrected);
        $this->assertCount(1, $this->router->cutoverHistory());
        $this->assertSame(ActiveColor::Blue, $this->router->cutoverHistory()[0]->activeColor);
    }

    public function test_simulated_edge_restart_reasserts_active_color(): void
    {
        $this->recordRelease(ActiveColor::Green, 3);
        $this->router->setLiveRoute(null);

        $result = $this->reconciler->reconcile('production');

        $this->assertTrue($result->drifted);
        $this->assertTrue($result->corrected);
        $this->assertCount(1, $this->router->cutoverHistory());
        $this->assertSame(ActiveColor::Green, $this->router->cutoverHistory()[0]->activeColor);
    }

    public function test_rate_limited_skips_corrective_reload(): void
    {
        $this->recordRelease(ActiveColor::Blue, 1);
        $this->router->setLiveRoute(new LiveRoute(ActiveColor::Green, 'app-green', 8082, 100));

        $this->reconciler->reconcile('production');

        $this->router->clearHistory();
        $this->router->setLiveRoute(new LiveRoute(ActiveColor::Green, 'app-green', 8082, 100));

        $result = $this->reconciler->reconcile('production');

        $this->assertTrue($result->skippedRateLimited);
        $this->assertFalse($result->corrected);
        $this->assertEmpty($this->router->cutoverHistory());
    }

    public function test_boot_reconcile_bypasses_rate_limiter(): void
    {
        $this->recordRelease(ActiveColor::Blue, 1);
        $this->router->setLiveRoute(null);

        $result = $this->reconciler->reconcile('production');

        $this->assertTrue($result->corrected);
        $this->assertFalse($result->skippedRateLimited);
    }

    private function recordRelease(ActiveColor $color, int $generation): void
    {
        $this->store->recordCurrentRelease(new CurrentRelease(
            env: 'production',
            activeColor: $color,
            imageDigest: 'sha256:' . str_repeat('ab', 32),
            buildId: 'build-' . $generation,
            planHash: 'sha256:plan',
            recordedAt: new \DateTimeImmutable(),
            generation: $generation,
        ));
    }
}
