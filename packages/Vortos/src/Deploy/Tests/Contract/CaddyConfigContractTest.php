<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Driver\Caddy\CaddyConfigFragment;
use Vortos\Deploy\Target\ActiveColor;

final class CaddyConfigContractTest extends TestCase
{
    public function test_config_fragment_produces_valid_json_structure(): void
    {
        $fragment = new CaddyConfigFragment();
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Blue,
            upstream: new ColorEndpoint('app-blue', 8081),
        );

        $config = $fragment->build($desired);

        $this->assertArrayHasKey('apps', $config);
        $this->assertArrayHasKey('http', $config['apps']);
        $this->assertArrayHasKey('servers', $config['apps']['http']);

        $server = $config['apps']['http']['servers']['app'];
        $this->assertArrayHasKey('listen', $server);
        $this->assertArrayHasKey('routes', $server);

        $route = $server['routes'][0];
        $this->assertArrayHasKey('handle', $route);

        $handler = $route['handle'][0];
        $this->assertSame('reverse_proxy', $handler['handler']);
        $this->assertArrayHasKey('upstreams', $handler);
        $this->assertSame('app-blue:8081', $handler['upstreams'][0]['dial']);
    }

    public function test_config_fragment_json_is_parseable(): void
    {
        $fragment = new CaddyConfigFragment();
        $desired = new DesiredRoute(
            env: 'production',
            activeColor: ActiveColor::Green,
            upstream: new ColorEndpoint('app-green', 8082),
        );

        $json = $fragment->toJson($desired);
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
}
