<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Runner;

final class TrustedProxyValidationTest extends TestCase
{
    public function test_wildcard_star_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Wildcard.*"\*"/');

        $this->callValidate(['*']);
    }

    public function test_remote_addr_wildcard_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Wildcard.*REMOTE_ADDR/');

        $this->callValidate(['REMOTE_ADDR']);
    }

    public function test_ipv4_slash_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Overly broad/');

        $this->callValidate(['0.0.0.0/0']);
    }

    public function test_ipv4_slash_four_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Overly broad/');

        $this->callValidate(['10.0.0.0/4']);
    }

    public function test_ipv6_slash_zero_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Overly broad/');

        $this->callValidate(['::/0']);
    }

    public function test_ipv6_slash_eight_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Overly broad/');

        $this->callValidate(['2001:db8::/8']);
    }

    public function test_invalid_ip_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid IP/');

        $this->callValidate(['not-an-ip']);
    }

    public function test_invalid_cidr_network_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid CIDR/');

        $this->callValidate(['garbage/24']);
    }

    public function test_non_string_entry_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a string/');

        $this->callValidate([42]);
    }

    public function test_valid_ipv4_passes(): void
    {
        $this->callValidate(['127.0.0.1']);
        $this->assertTrue(true);
    }

    public function test_valid_ipv6_passes(): void
    {
        $this->callValidate(['::1']);
        $this->assertTrue(true);
    }

    public function test_valid_cidr_passes(): void
    {
        $this->callValidate(['10.0.0.0/8', '192.168.1.0/24']);
        $this->assertTrue(true);
    }

    public function test_valid_ipv6_cidr_passes(): void
    {
        $this->callValidate(['2001:db8::/32']);
        $this->assertTrue(true);
    }

    public function test_empty_list_passes(): void
    {
        $this->callValidate([]);
        $this->assertTrue(true);
    }

    private function callValidate(array $proxies): void
    {
        $runner = new Runner('test', true, '/tmp/test-project');
        $ref = new \ReflectionMethod($runner, 'validateTrustedProxies');
        $ref->invoke($runner, $proxies);
    }
}
