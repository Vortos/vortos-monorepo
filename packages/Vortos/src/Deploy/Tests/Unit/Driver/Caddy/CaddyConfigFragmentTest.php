<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver\Caddy;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Driver\Caddy\CaddyConfigFragment;
use Vortos\Deploy\Target\ActiveColor;

final class CaddyConfigFragmentTest extends TestCase
{
    private CaddyConfigFragment $fragment;

    protected function setUp(): void
    {
        $this->fragment = new CaddyConfigFragment();
    }

    public function test_builds_correct_upstream_for_blue(): void
    {
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $config = $this->fragment->build($desired);

        $dial = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'][0]['dial'];
        $this->assertSame('app-blue:8081', $dial);
    }

    public function test_builds_correct_upstream_for_green(): void
    {
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-green', 8082),
        );

        $config = $this->fragment->build($desired);

        $dial = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'][0]['dial'];
        $this->assertSame('app-green:8082', $dial);
    }

    public function test_includes_health_check_uri(): void
    {
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $config = $this->fragment->build($desired);
        $health = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0]['health_checks']['active'];

        $this->assertSame('/health/ready', $health['uri']);
    }

    public function test_json_round_trip_is_deterministic(): void
    {
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $json1 = $this->fragment->toJson($desired);
        $json2 = $this->fragment->toJson($desired);

        $this->assertSame($json1, $json2, 'JSON output must be byte-stable (deterministic).');
    }

    public function test_from_json_round_trip(): void
    {
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $json = $this->fragment->toJson($desired);
        $parsed = $this->fragment->fromJson($json);

        $this->assertSame($this->fragment->build($desired), $parsed);
    }
}
