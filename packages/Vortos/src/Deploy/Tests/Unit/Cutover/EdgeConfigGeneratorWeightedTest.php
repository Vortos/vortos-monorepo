<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;

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
        self::assertContains('app-blue:8081', $dials);
        self::assertContains('app-green:8081', $dials);

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

        self::assertSame(5, $byDial['app-blue:8081'] ?? null);
        self::assertSame(95, $byDial['app-green:8081'] ?? null);
    }

    public function test_100_0_emits_single_upstream_backward_compat(): void
    {
        $config = $this->generator->generateWeightedCaddyConfig('example.com', 100, 0);

        $handler = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        // Backward compat: single upstream, no load_balancing key
        self::assertArrayNotHasKey('load_balancing', $handler);
        self::assertCount(1, $handler['upstreams']);
        self::assertSame('app-blue:8081', $handler['upstreams'][0]['dial']);
    }

    public function test_0_100_emits_single_green_upstream(): void
    {
        $config = $this->generator->generateWeightedCaddyConfig('example.com', 0, 100);

        $handler = $config['apps']['http']['servers']['app']['routes'][0]['handle'][0];

        self::assertArrayNotHasKey('load_balancing', $handler);
        self::assertCount(1, $handler['upstreams']);
        self::assertSame('app-green:8081', $handler['upstreams'][0]['dial']);
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
