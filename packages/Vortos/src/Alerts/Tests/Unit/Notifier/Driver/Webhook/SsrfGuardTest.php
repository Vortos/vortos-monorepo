<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Notifier\Driver\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfGuard;
use Vortos\Alerts\Notifier\Driver\Webhook\SsrfViolationException;

final class SsrfGuardTest extends TestCase
{
    private function guard(): SsrfGuard
    {
        // Deterministic resolver: avoids real DNS lookups in CI, while still exercising
        // the same IP-range deny-list logic SsrfGuard applies to a resolved address.
        return new SsrfGuard(resolver: function (string $host): array {
            return match ($host) {
                'public.example.invalid' => ['93.184.216.34'],
                'metadata.internal' => ['169.254.169.254'],
                'loopback.internal' => ['127.0.0.1'],
                'private10.internal' => ['10.1.2.3'],
                'private172.internal' => ['172.16.5.5'],
                'private192.internal' => ['192.168.1.1'],
                'ula.internal' => ['fc00::1'],
                'v6loopback.internal' => ['::1'],
                default => [],
            };
        });
    }

    public function test_public_https_host_is_allowed(): void
    {
        $this->guard()->assertSafe('https://public.example.invalid/webhook');
        $this->addToAssertionCount(1);
    }

    public function test_metadata_ip_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://metadata.internal/latest/meta-data/');
    }

    public function test_loopback_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://loopback.internal/');
    }

    public function test_private_10_range_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://private10.internal/');
    }

    public function test_private_172_range_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://private172.internal/');
    }

    public function test_private_192_range_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://private192.internal/');
    }

    public function test_ipv6_ula_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://ula.internal/');
    }

    public function test_ipv6_loopback_is_blocked(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('https://v6loopback.internal/');
    }

    public function test_non_https_scheme_is_blocked_by_default(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('http://public.example.invalid/webhook');
    }

    public function test_insecure_scheme_allowed_when_explicitly_opted_in(): void
    {
        $guard = new SsrfGuard(allowInsecureScheme: true, resolver: fn (string $host): array => ['93.184.216.34']);
        $guard->assertSafe('http://public.example.invalid/webhook');
        $this->addToAssertionCount(1);
    }

    public function test_malformed_url_rejected(): void
    {
        $this->expectException(SsrfViolationException::class);
        $this->guard()->assertSafe('not a url');
    }

    public function test_direct_ip_literal_is_checked_without_resolution(): void
    {
        $guard = new SsrfGuard();
        $this->expectException(SsrfViolationException::class);
        $guard->assertSafe('https://169.254.169.254/latest/meta-data/');
    }
}
