<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\SdkKey;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\SdkKey\IpAllowlistChecker;

final class IpAllowlistCheckerTest extends TestCase
{
    private IpAllowlistChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new IpAllowlistChecker();
    }

    public function test_exact_ipv4_match(): void
    {
        $this->assertTrue($this->checker->isAllowed('192.168.1.1', ['192.168.1.1/32']));
    }

    public function test_ipv4_cidr_match(): void
    {
        $this->assertTrue($this->checker->isAllowed('192.168.1.100', ['192.168.1.0/24']));
    }

    public function test_ipv4_cidr_no_match(): void
    {
        $this->assertFalse($this->checker->isAllowed('192.168.2.1', ['192.168.1.0/24']));
    }

    public function test_ipv4_without_prefix_treated_as_slash32(): void
    {
        $this->assertTrue($this->checker->isAllowed('10.0.0.1', ['10.0.0.1']));
        $this->assertFalse($this->checker->isAllowed('10.0.0.2', ['10.0.0.1']));
    }

    public function test_ipv6_loopback(): void
    {
        $this->assertTrue($this->checker->isAllowed('::1', ['::1/128']));
    }

    public function test_ipv6_cidr(): void
    {
        $this->assertTrue($this->checker->isAllowed('2001:db8::1', ['2001:db8::/32']));
        $this->assertFalse($this->checker->isAllowed('2001:db9::1', ['2001:db8::/32']));
    }

    public function test_multiple_cidrs_any_match(): void
    {
        $this->assertTrue($this->checker->isAllowed('10.0.0.5', ['192.168.0.0/16', '10.0.0.0/8']));
    }

    public function test_empty_list_denies_all(): void
    {
        $this->assertFalse($this->checker->isAllowed('1.2.3.4', []));
    }

    public function test_invalid_ip_returns_false(): void
    {
        $this->assertFalse($this->checker->isAllowed('not-an-ip', ['0.0.0.0/0']));
    }

    public function test_ipv4_ipv6_mismatch_returns_false(): void
    {
        $this->assertFalse($this->checker->isAllowed('192.168.1.1', ['::1/128']));
    }
}
