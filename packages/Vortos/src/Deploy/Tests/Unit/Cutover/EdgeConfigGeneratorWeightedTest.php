<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;

final class EdgeConfigGeneratorWeightedTest extends TestCase
{
    private EdgeConfigGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new EdgeConfigGenerator();
    }

    public function test_weighted_config_emits_two_upstreams_with_weighted_round_robin(): void
    {
        $config = $this->generator->generateWeightedCaddyConfig('example.com', 95, 5);

        $handler = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        self::assertSame('weighted_round_robin', $handler['load_balancing']['selection_policy']['policy']);
        self::assertCount(2, $handler['upstreams']);

        $dials = array_column($handler['upstreams'], 'dial');
        self::assertContains('app-blue:8080', $dials);
        self::assertContains('app-green:8080', $dials);

        // Weights sum should make sense
        $weights = array_column($handler['upstreams'], 'weight');
        self::assertSame(100, array_sum($weights));
    }

    public function test_canary_5_percent_weights_correct(): void
    {
        $config = $this->generator->generateWeightedCaddyConfig('example.com', 5, 95);
        $handler = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        $byDial = [];
        foreach ($handler['upstreams'] as $u) {
            $byDial[$u['dial']] = $u['weight'];
        }

        self::assertSame(5, $byDial['app-blue:8080'] ?? null);
        self::assertSame(95, $byDial['app-green:8080'] ?? null);
    }

    public function test_100_0_emits_single_upstream_backward_compat(): void
    {
        $config = $this->generator->generateWeightedCaddyConfig('example.com', 100, 0);

        $handler = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        // Backward compat: single upstream, no load_balancing key
        self::assertArrayNotHasKey('load_balancing', $handler);
        self::assertCount(1, $handler['upstreams']);
        self::assertSame('app-blue:8080', $handler['upstreams'][0]['dial']);
    }

    public function test_0_100_emits_single_green_upstream(): void
    {
        $config = $this->generator->generateWeightedCaddyConfig('example.com', 0, 100);

        $handler = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        self::assertArrayNotHasKey('load_balancing', $handler);
        self::assertCount(1, $handler['upstreams']);
        self::assertSame('app-green:8080', $handler['upstreams'][0]['dial']);
    }

    public function test_dial_port_comes_from_the_runtime_spec(): void
    {
        // Watch-list: the dial port is the RuntimeServiceSpec container port, not a hardcoded 8081.
        $generator = EdgeConfigGenerator::fromSpec(new RuntimeServiceSpec(containerPort: 9000));

        $config = $generator->generateWeightedCaddyConfig('example.com', 60, 40);
        $dials = array_column($config['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'], 'dial');

        self::assertContains('app-blue:9000', $dials);
        self::assertContains('app-green:9000', $dials);
    }

    public function test_default_dial_port_matches_spec_default(): void
    {
        $config = $this->generator->generateCaddyConfig('example.com');
        $dial = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'][0]['dial'];

        self::assertSame('app-blue:' . RuntimeServiceSpec::DEFAULT_CONTAINER_PORT, $dial);
    }

    public function test_invalid_weight_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generateWeightedCaddyConfig('example.com', 150, 50);
    }

    public function test_json_output_is_valid_json(): void
    {
        $json = $this->generator->generateWeightedCaddyConfigJson('example.com', 25, 75);

        self::assertJson($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
    }
}
