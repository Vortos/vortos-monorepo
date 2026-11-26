<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\DockerNetwork;

final class DockerNetworkTest extends TestCase
{
    public function test_a_subnet_is_optional_and_carried_through(): void
    {
        self::assertNull(DockerNetwork::serving('vortos-edge')->subnet);
        self::assertSame('172.22.0.0/16', DockerNetwork::serving('vortos-edge', '172.22.0.0/16')->subnet);
        self::assertSame('10.9.0.0/24', DockerNetwork::control('vortos-control', '10.9.0.0/24')->toArray()['subnet']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSubnets(): iterable
    {
        yield 'not a cidr' => ['172.22.0.0'];
        yield 'bad address' => ['172.22.0.300/16'];
        yield 'ipv6' => ['fd00::/64'];
        yield 'narrower than /28' => ['172.22.0.0/30'];
        yield 'host bits set' => ['172.22.0.4/16'];
        yield 'public range' => ['8.8.0.0/16'];
        yield 'straddles 172.16/12' => ['172.0.0.0/8'];
        yield 'just outside 172.16/12' => ['172.32.0.0/16'];
        yield 'shell metacharacters' => ['172.22.0.0/16;id'];
    }

    #[DataProvider('invalidSubnets')]
    public function test_only_a_private_ipv4_network_address_is_accepted(string $subnet): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DockerNetwork::serving('vortos-edge', $subnet);
    }

    public function test_every_rfc1918_range_is_accepted(): void
    {
        foreach (['10.0.0.0/8', '172.16.0.0/12', '172.31.255.0/24', '192.168.0.0/16', '192.168.7.16/28'] as $subnet) {
            self::assertSame($subnet, DockerNetwork::serving('n', $subnet)->subnet);
        }
    }

    public function test_overlap_is_detected_in_either_direction(): void
    {
        $wide = DockerNetwork::serving('a', '172.22.0.0/16');
        $inside = DockerNetwork::serving('b', '172.22.5.0/24');
        $apart = DockerNetwork::serving('c', '172.23.0.0/16');
        $undeclared = DockerNetwork::serving('d');

        self::assertTrue($wide->overlaps($inside));
        self::assertTrue($inside->overlaps($wide));
        self::assertFalse($wide->overlaps($apart));
        self::assertFalse($wide->overlaps($undeclared));
    }
}
