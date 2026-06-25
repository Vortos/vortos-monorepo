<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\LiveRoute;
use Vortos\Deploy\Target\ActiveColor;

final class LiveRouteTest extends TestCase
{
    public function test_equals_desired_when_matching(): void
    {
        $live = new LiveRoute(ActiveColor::Blue, 'app-blue', 8081, 100);
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $this->assertTrue($live->equalsDesired($desired));
    }

    public function test_not_equals_desired_on_color_mismatch(): void
    {
        $live = new LiveRoute(ActiveColor::Blue, 'app-blue', 8081, 100);
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $this->assertFalse($live->equalsDesired($desired));
    }

    public function test_not_equals_desired_on_port_mismatch(): void
    {
        $live = new LiveRoute(ActiveColor::Blue, 'app-blue', 8081, 100);
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 9999),
        );

        $this->assertFalse($live->equalsDesired($desired));
    }

    public function test_not_equals_desired_on_host_mismatch(): void
    {
        $live = new LiveRoute(ActiveColor::Blue, 'app-blue', 8081, 100);
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-green', 8081),
        );

        $this->assertFalse($live->equalsDesired($desired));
    }

    public function test_to_array(): void
    {
        $live = new LiveRoute(ActiveColor::Green, 'app-green', 8082, 100);
        $arr = $live->toArray();

        $this->assertSame('green', $arr['active_color']);
        $this->assertSame('app-green', $arr['upstream_host']);
        $this->assertSame(8082, $arr['upstream_port']);
        $this->assertSame(100, $arr['weight']);
    }
}
