<?php

declare(strict_types=1);

namespace Vortos\Security\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Foundation\Doctor\DoctorStatus;
use Vortos\Security\Doctor\TrustedProxyDoctorCheck;

final class TrustedProxyDoctorCheckTest extends TestCase
{
    public function test_empty_proxies_with_ip_rate_limits_returns_error(): void
    {
        $check = new TrustedProxyDoctorCheck([], true);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Error, $result->status);
        $this->assertStringContainsString('IP-scoped rate limits', $result->summary);
    }

    public function test_empty_proxies_without_ip_rate_limits_returns_warning(): void
    {
        $check = new TrustedProxyDoctorCheck([], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Warning, $result->status);
        $this->assertStringContainsString('trusted_proxies is empty', $result->summary);
    }

    public function test_wildcard_star_returns_error(): void
    {
        $check = new TrustedProxyDoctorCheck(['*'], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Error, $result->status);
        $this->assertStringContainsString('Wildcard', $result->summary);
    }

    public function test_remote_addr_wildcard_returns_error(): void
    {
        $check = new TrustedProxyDoctorCheck(['REMOTE_ADDR'], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Error, $result->status);
        $this->assertStringContainsString('Wildcard', $result->summary);
    }

    public function test_overly_broad_ipv4_cidr_returns_error(): void
    {
        $check = new TrustedProxyDoctorCheck(['0.0.0.0/0'], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Error, $result->status);
        $this->assertStringContainsString('Overly broad', $result->summary);
    }

    public function test_overly_broad_ipv6_cidr_returns_error(): void
    {
        $check = new TrustedProxyDoctorCheck(['::/0'], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Error, $result->status);
        $this->assertStringContainsString('Overly broad', $result->summary);
    }

    public function test_valid_proxies_returns_ok(): void
    {
        $check = new TrustedProxyDoctorCheck(['127.0.0.1', '::1'], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Ok, $result->status);
        $this->assertStringContainsString('2 entries', $result->summary);
    }

    public function test_valid_cidr_returns_ok(): void
    {
        $check = new TrustedProxyDoctorCheck(['10.0.0.0/8'], false);
        $result = $check->run();

        $this->assertSame(DoctorStatus::Ok, $result->status);
    }

    public function test_name_is_stable(): void
    {
        $check = new TrustedProxyDoctorCheck([], false);
        $this->assertSame('security.trusted-proxies', $check->name());
    }
}
