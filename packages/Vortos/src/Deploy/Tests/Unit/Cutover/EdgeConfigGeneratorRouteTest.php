<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Target\ActiveColor;

/**
 * GAP-D (D2): the cutover config is built by the single-source-of-truth EdgeConfigGenerator and
 * always carries the domain host matcher + tls.automation, so a /load preserves the certificate. The
 * canary complement dial is derived from the route's container port, never a hardcoded 8081.
 */
final class EdgeConfigGeneratorRouteTest extends TestCase
{
    private EdgeConfigGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new EdgeConfigGenerator();
    }

    public function test_route_with_domain_includes_host_matcher_and_tls(): void
    {
        $config = $this->generator->generateForRoute($this->route(ActiveColor::Blue, 8080, 'api.example.com'), 'edge:2019');

        $route = $config['apps']['http']['servers']['app']['routes'][0];
        self::assertSame(['api.example.com'], $route['match'][0]['host']);
        self::assertSame(['api.example.com'], $config['apps']['tls']['automation']['policies'][0]['subjects']);
        self::assertSame('app-blue:8080', $route['handle'][0]['upstreams'][0]['dial']);
        self::assertSame('edge:2019', $config['admin']['listen']);
        self::assertSame('/health/ready', $route['handle'][0]['health_checks']['active']['uri']);
    }

    public function test_route_without_domain_omits_host_matcher_and_tls(): void
    {
        $config = $this->generator->generateForRoute($this->route(ActiveColor::Green, 8080, null));

        $route = $config['apps']['http']['servers']['app']['routes'][0];
        self::assertArrayNotHasKey('match', $route);
        self::assertArrayNotHasKey('tls', $config['apps']);
        self::assertSame('app-green:8080', $route['handle'][0]['upstreams'][0]['dial']);
    }

    public function test_weighted_route_complement_dial_uses_route_port_not_hardcoded_8081(): void
    {
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 9000),
            weight: 70,
            domain: 'api.example.com',
        );

        $handler = $this->generator->generateForRoute($desired)['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        self::assertSame('weighted_round_robin', $handler['load_balancing']['selection_policy']['policy']);
        $byDial = [];
        foreach ($handler['upstreams'] as $u) {
            $byDial[$u['dial']] = $u['weight'];
        }
        self::assertSame(70, $byDial['app-blue:9000'] ?? null);
        // Complement is the opposite color on the SAME container port — never a hardcoded 8081.
        self::assertSame(30, $byDial['app-green:9000'] ?? null);
        self::assertArrayNotHasKey('app-green:8081', $byDial);
    }

    public function test_full_weight_is_a_single_upstream(): void
    {
        $handler = $this->generator->generateForRoute($this->route(ActiveColor::Blue, 8080, 'api.example.com'))
            ['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        self::assertArrayNotHasKey('load_balancing', $handler);
        self::assertCount(1, $handler['upstreams']);
    }

    private function route(ActiveColor $color, int $port, ?string $domain): DesiredRoute
    {
        return new DesiredRoute(
            env: 'production',
            activeColor: $color,
            upstream: new ColorEndpoint('app-' . $color->value, $port),
            domain: $domain,
        );
    }
}
