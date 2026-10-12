<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Cutover\Edge\EdgeNetworkTopology;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;

final class EdgeComposeTopologyTest extends TestCase
{
    public function test_an_isolated_edge_serves_on_its_own_network_and_answers_admin_only_on_the_admin_network(): void
    {
        $yaml = (new EdgeConfigGenerator(8080, topology: new EdgeNetworkTopology('vortos-edge', 'vortos-net', 'vortos-edge-admin')))
            ->generateEdgeComposeYaml('example.com');

        $edge = substr($yaml, strpos($yaml, "  edge:\n"));
        $edgeNetworks = substr($edge, strrpos($edge, "    networks:\n"), strpos($edge, "\nvolumes:") - strrpos($edge, "    networks:\n"));

        self::assertStringContainsString('vortos-edge: {}', $edgeNetworks);
        self::assertStringContainsString("vortos-edge-admin:\n        aliases:\n          - edge-admin", $edgeNetworks);
        // The internet-facing edge must not be on the network the database, cache, broker and
        // Docker API proxy are on.
        self::assertStringNotContainsString('vortos-net', $edgeNetworks);

        // edge-init still renders its config from the state store on the data network.
        $init = substr($yaml, strpos($yaml, "  edge-init:\n"), strpos($yaml, "  edge:\n") - strpos($yaml, "  edge-init:\n"));
        self::assertStringContainsString("networks:\n      - vortos-net", $init);
    }

    public function test_the_edge_is_hardened_and_reads_its_config_through_the_group(): void
    {
        $yaml = (new EdgeConfigGenerator(8080, topology: EdgeNetworkTopology::shared('vortos-net')))->generateEdgeComposeYaml('example.com');

        self::assertStringContainsString("cap_drop:\n      - ALL\n    cap_add:\n      - NET_BIND_SERVICE", $yaml);
        self::assertStringContainsString('no-new-privileges:true', $yaml);
        self::assertStringContainsString('read_only: true', $yaml);
        self::assertStringContainsString('"${EDGE_CONFIG_GID:-0}"', $yaml);
        self::assertStringNotContainsString('- DAC_OVERRIDE', $yaml);
    }

    public function test_the_admin_network_cannot_be_the_serving_network(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EdgeNetworkTopology('vortos-edge', 'vortos-net', 'vortos-edge');
    }
}
