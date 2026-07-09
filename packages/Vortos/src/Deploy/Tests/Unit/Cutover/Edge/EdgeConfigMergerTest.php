<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover\Edge;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Cutover\Edge\AppProxyIdentifier;
use Vortos\Deploy\Cutover\Edge\EdgeConfigMerger;
use Vortos\Deploy\Cutover\Edge\MergeAction;
use Vortos\Deploy\Target\ActiveColor;

final class EdgeConfigMergerTest extends TestCase
{
    private EdgeConfigMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new EdgeConfigMerger(new AppProxyIdentifier());
    }

    private function route(ActiveColor $color, int $weight = 100): DesiredRoute
    {
        return new DesiredRoute(
            env: 'production',
            activeColor: $color,
            upstream: new ColorEndpoint('app-' . $color->value, 8080),
            weight: $weight,
            domain: 'example.com',
        );
    }

    /** @return array<string,mixed> */
    private function baseWithAppProxy(): array
    {
        return [
            'apps' => ['http' => ['servers' => ['srv0' => [
                'listen' => [':443'],
                'routes' => [[
                    'match' => [['host' => ['example.com']]],
                    'handle' => [[
                        'handler' => 'reverse_proxy',
                        'upstreams' => [['dial' => 'app-blue:8080']],
                        'headers' => ['request' => ['set' => ['X-Real-IP' => ['{remote_host}']]]],
                        'transport' => ['protocol' => 'http', 'read_timeout' => '30s'],
                    ]],
                ]],
            ]]]],
        ];
    }

    public function testPatchesUpstreamToLiveColorAndPreservesOtherFields(): void
    {
        $outcome = $this->merger->merge($this->baseWithAppProxy(), $this->route(ActiveColor::Green));

        self::assertSame(MergeAction::Patched, $outcome->action);
        $handler = $outcome->config['apps']['http']['servers']['srv0']['routes'][0]['handle'][0];

        self::assertSame([['dial' => 'app-green:8080']], $handler['upstreams']);
        // Operator's own fields survive byte-for-byte.
        self::assertSame(['request' => ['set' => ['X-Real-IP' => ['{remote_host}']]]], $handler['headers']);
        self::assertSame(['protocol' => 'http', 'read_timeout' => '30s'], $handler['transport']);
    }

    public function testCanaryInjectsWeightedUpstreams(): void
    {
        $outcome = $this->merger->merge($this->baseWithAppProxy(), $this->route(ActiveColor::Green, weight: 90));

        $handler = $outcome->config['apps']['http']['servers']['srv0']['routes'][0]['handle'][0];

        self::assertSame(['policy' => 'weighted_round_robin'], $handler['load_balancing']['selection_policy']);
        self::assertSame([
            ['dial' => 'app-green:8080', 'weight' => 90],
            ['dial' => 'app-blue:8080', 'weight' => 10],
        ], $handler['upstreams']);
    }

    public function testInsertsAppProxyWhenAbsent(): void
    {
        $base = [
            'apps' => ['http' => ['servers' => ['srv0' => [
                'listen' => [':443'],
                'routes' => [[
                    'match' => [['host' => ['example.com']]],
                    'handle' => [['handler' => 'encode', 'encodings' => ['gzip' => []]]],
                ]],
            ]]]],
        ];

        $outcome = $this->merger->merge($base, $this->route(ActiveColor::Blue));

        self::assertSame(MergeAction::Inserted, $outcome->action);
        $handle = $outcome->config['apps']['http']['servers']['srv0']['routes'][0]['handle'];
        self::assertCount(2, $handle);
        self::assertSame('encode', $handle[0]['handler']);
        self::assertSame('reverse_proxy', $handle[1]['handler']);
        self::assertSame([['dial' => 'app-blue:8080']], $handle[1]['upstreams']);
    }

    public function testAddsTlsPolicyWhenMissing(): void
    {
        $outcome = $this->merger->merge($this->baseWithAppProxy(), $this->route(ActiveColor::Green));

        self::assertSame(
            [['subjects' => ['example.com']]],
            $outcome->config['apps']['tls']['automation']['policies'],
        );
    }

    public function testRespectsExistingCatchAllTlsPolicy(): void
    {
        $base = $this->baseWithAppProxy();
        $base['apps']['tls']['automation']['policies'] = [['issuers' => [['module' => 'internal']]]];

        $outcome = $this->merger->merge($base, $this->route(ActiveColor::Green));

        // Catch-all policy left untouched (operator's issuer honored), no extra policy appended.
        self::assertSame(
            [['issuers' => [['module' => 'internal']]]],
            $outcome->config['apps']['tls']['automation']['policies'],
        );
    }

    public function testDeterministicOutput(): void
    {
        $a = $this->merger->merge($this->baseWithAppProxy(), $this->route(ActiveColor::Green));
        $b = $this->merger->merge($this->baseWithAppProxy(), $this->route(ActiveColor::Green));

        self::assertSame($a->sha256, $b->sha256);
    }
}
