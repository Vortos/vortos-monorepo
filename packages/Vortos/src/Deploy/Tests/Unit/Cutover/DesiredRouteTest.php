<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Target\ActiveColor;

final class DesiredRouteTest extends TestCase
{
    public function test_construction_with_defaults(): void
    {
        $route = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $this->assertSame('production', $route->env);
        $this->assertSame(ActiveColor::Blue, $route->activeColor);
        $this->assertSame(30, $route->drainDeadlineSeconds);
        $this->assertSame(100, $route->weight);
    }

    public function test_rejects_zero_drain_deadline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DesiredRoute(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            drainDeadlineSeconds: 0,
        );
    }

    public function test_rejects_negative_weight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DesiredRoute(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            weight: -1,
        );
    }

    public function test_rejects_weight_over_100(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DesiredRoute(
            env: 'prod',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            weight: 101,
        );
    }

    public function test_to_array(): void
    {
        $route = new DesiredRoute(
            env: 'staging',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-green', 8082),
            drainDeadlineSeconds: 15,
            weight: 100,
        );

        $arr = $route->toArray();
        $this->assertSame('staging', $arr['env']);
        $this->assertSame('green', $arr['active_color']);
        $this->assertSame('app-green', $arr['upstream_host']);
        $this->assertSame(8082, $arr['upstream_port']);
        $this->assertSame(15, $arr['drain_deadline_seconds']);
        $this->assertSame(100, $arr['weight']);
    }
}
