<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\CutoverResult;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeRouterCapability;
use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class EdgeRouterConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createRouter(): EdgeRouterInterface;

    protected function createDriver(): EdgeRouterInterface
    {
        return $this->createRouter();
    }

    final public function test_cutover_returns_populated_result(): void
    {
        $router = $this->createRouter();
        $desired = $this->makeDesiredRoute(ActiveColor::Blue);

        $result = $router->cutover($desired);

        $this->assertInstanceOf(CutoverResult::class, $result);
        $this->assertTrue($result->succeeded);
        $this->assertTrue($result->verifiedLiveUpstream);
    }

    final public function test_live_route_reflects_last_cutover(): void
    {
        $router = $this->createRouter();
        $desired = $this->makeDesiredRoute(ActiveColor::Green);

        $router->cutover($desired);
        $live = $router->liveRoute();

        $this->assertInstanceOf(LiveRoute::class, $live);
        $this->assertSame(ActiveColor::Green, $live->activeColor);
        $this->assertSame($desired->upstream->host, $live->upstreamHost);
        $this->assertSame($desired->upstream->port, $live->upstreamPort);
    }

    final public function test_reconcile_is_idempotent_when_in_sync(): void
    {
        $router = $this->createRouter();
        $desired = $this->makeDesiredRoute(ActiveColor::Blue);

        $router->cutover($desired);
        $result = $router->reconcile($desired);

        $this->assertTrue($result->inSync);
        $this->assertFalse($result->drifted);
    }

    final public function test_reconcile_corrects_drift(): void
    {
        $router = $this->createRouter();
        $blue = $this->makeDesiredRoute(ActiveColor::Blue);
        $green = $this->makeDesiredRoute(ActiveColor::Green);

        $router->cutover($blue);
        $result = $router->reconcile($green);

        $this->assertTrue($result->drifted);
        $this->assertTrue($result->corrected);
    }

    final public function test_declares_edge_router_capabilities(): void
    {
        $capArray = $this->createRouter()->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey(EdgeRouterCapability::ConnectionDraining->value, $capArray);
        $this->assertArrayHasKey(EdgeRouterCapability::AtomicSwap->value, $capArray);
        $this->assertArrayHasKey(EdgeRouterCapability::VerifiedCutover->value, $capArray);
        $this->assertArrayHasKey(EdgeRouterCapability::WeightedUpstreams->value, $capArray);
        $this->assertArrayHasKey(EdgeRouterCapability::DurableState->value, $capArray);
    }

    final public function test_rejects_weighted_route_when_unsupported(): void
    {
        $router = $this->createRouter();
        $caps = $router->capabilities();

        if ($caps->supports(EdgeRouterCapability::WeightedUpstreams)) {
            $this->markTestSkipped('Router supports weighted upstreams.');
        }

        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            drainDeadlineSeconds: 5,
            weight: 50,
        );

        $this->assertRejectsUnsupportedCapability(fn () => $router->cutover($desired));
    }

    protected function makeDesiredRoute(ActiveColor $color): DesiredRoute
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
