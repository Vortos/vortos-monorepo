<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Target\ActiveColor;

final class CaddyConfigContractTest extends TestCase
{
    public function test_route_config_produces_valid_json_structure(): void
    {
        $generator = new EdgeConfigGenerator();
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
            domain: 'example.com',
        );

        $config = $generator->generateForRoute($desired, 'edge:2019');

        $this->assertArrayHasKey('apps', $config);
        $this->assertArrayHasKey('http', $config['apps']);
        $this->assertArrayHasKey('servers', $config['apps']['http']);

        $server = $config['apps']['http']['servers']['app'];
        $this->assertArrayHasKey('listen', $server);
        $this->assertArrayHasKey('routes', $server);

        $route = $server['routes'][0];
        $this->assertArrayHasKey('handle', $route);
        // A domain'd route carries a host matcher so it never clobbers other vhosts.
        $this->assertSame(['example.com'], $route['match'][0]['host']);

        $handler = $route['handle'][0];
        $this->assertSame('reverse_proxy', $handler['handler']);
        $this->assertArrayHasKey('upstreams', $handler);
        $this->assertSame('app-blue:8081', $handler['upstreams'][0]['dial']);

        // GAP-D: the cutover config must retain the domain's TLS automation policy.
        $this->assertSame(['example.com'], $config['apps']['tls']['automation']['policies'][0]['subjects']);
        // The admin bind must echo the edge's real listen address (decoupled from the connect URL).
        $this->assertSame('edge:2019', $config['admin']['listen']);
    }

    public function test_route_config_json_is_parseable(): void
    {
        $generator = new EdgeConfigGenerator();
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-green', 8082),
            domain: 'example.com',
        );

        $json = $generator->generateForRouteJson($desired);
        $parsed = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('apps', $parsed);
    }

    public function test_edge_config_generator_produces_valid_caddy_json(): void
    {
        $generator = new EdgeConfigGenerator();
        $json = $generator->generateCaddyConfigJson('example.com');

        $parsed = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('admin', $parsed);
        $this->assertArrayHasKey('apps', $parsed);
        $this->assertArrayHasKey('http', $parsed['apps']);
        $this->assertArrayHasKey('tls', $parsed['apps']);
    }

    public function test_edge_config_admin_not_bound_to_public(): void
    {
        $generator = new EdgeConfigGenerator();
        $config = $generator->generateCaddyConfig('example.com');

        $listen = $config['admin']['listen'];
        $this->assertStringNotContainsString('0.0.0.0:2019', $listen);
    }

    public function test_edge_compose_yaml_has_expected_structure(): void
    {
        $generator = new EdgeConfigGenerator();
        $yaml = $generator->generateEdgeComposeYaml('example.com');

        $this->assertStringContainsString('services:', $yaml);
        $this->assertStringContainsString('edge:', $yaml);
        $this->assertStringContainsString('caddy:', $yaml);
        $this->assertStringContainsString('vortos-net', $yaml);
        $this->assertStringNotContainsString('2019:', $yaml);
    }

    public function test_edge_compose_boots_from_durable_persisted_config(): void
    {
        $generator = new EdgeConfigGenerator();
        $yaml = $generator->generateEdgeComposeYaml('example.com');

        // The edge must boot from a config file (config-as-code), not admin-API-only in-memory state.
        $this->assertStringContainsString('caddy run --config /config/caddy.json', $yaml);

        // /config is a HOST bind-mount (survives a Docker daemon restart + is writable by the cutover
        // over SSH), not the old ephemeral named volume that was lost on restart.
        $this->assertStringContainsString('${EDGE_CONFIG_DIR:-/opt/vortos/edge/config}:/config', $yaml);
        $this->assertStringNotContainsString('edge_runtime', $yaml);

        // TLS/ACME material stays on a durable named volume so a cold boot never re-issues certs.
        $this->assertStringContainsString('caddy_data:/data', $yaml);
    }
}
