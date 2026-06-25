<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\DeployK8s\Api\KubeApiConflictException;
use Vortos\DeployK8s\Edge\KubernetesEdgeRouter;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;

final class KubernetesEdgeRouterTest extends TestCase
{
    private FakeKubeApi $kubeApi;
    private KubernetesEdgeRouter $router;

    protected function setUp(): void
    {
        $this->kubeApi = new FakeKubeApi();
        $this->kubeApi->seedService('app', 'default', ['app.kubernetes.io/color' => 'blue'], '1', 8080);
        $this->router = new KubernetesEdgeRouter($this->kubeApi, 'app', 'default');
    }

    public function test_cutover_patches_selector(): void
    {
        $desired = $this->makeRoute(ActiveColor::Green);
        $result = $this->router->cutover($desired);

        $this->assertTrue($result->succeeded);
        $this->assertTrue($result->verifiedLiveUpstream);

        $patchOps = array_filter($this->kubeApi->ops, fn ($op) => $op['op'] === 'patchServiceSelector');
        $this->assertNotEmpty($patchOps);
    }

    public function test_cutover_cas_conflict_throws(): void
    {
        $this->kubeApi->setConflictOnNextPatch();

        $desired = $this->makeRoute(ActiveColor::Green);

        $this->expectException(KubeApiConflictException::class);
        $this->router->cutover($desired);
    }

    public function test_live_route_returns_null_when_no_service(): void
    {
        $router = new KubernetesEdgeRouter($this->kubeApi, 'nonexistent', 'default');
        $this->assertNull($router->liveRoute());
    }

    public function test_live_route_reflects_service_selector(): void
    {
        $live = $this->router->liveRoute();
        $this->assertNotNull($live);
        $this->assertSame(ActiveColor::Blue, $live->activeColor);
    }

    public function test_live_route_after_cutover_reflects_new_color(): void
    {
        $desired = $this->makeRoute(ActiveColor::Green);
        $this->router->cutover($desired);

        $live = $this->router->liveRoute();
        $this->assertNotNull($live);
        $this->assertSame(ActiveColor::Green, $live->activeColor);
    }

    public function test_reconcile_in_sync(): void
    {
        $desired = $this->makeRoute(ActiveColor::Blue);

        $this->router->cutover($desired);
        $result = $this->router->reconcile($desired);

        $this->assertTrue($result->inSync);
        $this->assertFalse($result->drifted);
    }

    public function test_reconcile_corrects_drift(): void
    {
        $blue = $this->makeRoute(ActiveColor::Blue);
        $green = $this->makeRoute(ActiveColor::Green);

        $this->router->cutover($blue);
        $result = $this->router->reconcile($green);

        $this->assertTrue($result->drifted);
        $this->assertTrue($result->corrected);
    }

    public function test_capabilities_declare_all_edge_capabilities(): void
    {
        $caps = $this->router->capabilities();
        $this->assertTrue($caps->supports('connection_draining'));
        $this->assertTrue($caps->supports('atomic_swap'));
        $this->assertTrue($caps->supports('verified_cutover'));
        $this->assertTrue($caps->supports('weighted_upstreams'));
        $this->assertTrue($caps->supports('durable_state'));
    }

    private function makeRoute(ActiveColor $color): DesiredRoute
    {
        $host = $color === ActiveColor::Blue ? 'app-blue' : 'app-green';
        $port = $color === ActiveColor::Blue ? 8081 : 8082;

        return new DesiredRoute(
            env: 'production',
            activeColor: $color,
            upstream: new ColorEndpoint($host, $port),
            drainDeadlineSeconds: 5,
        );
    }
}
