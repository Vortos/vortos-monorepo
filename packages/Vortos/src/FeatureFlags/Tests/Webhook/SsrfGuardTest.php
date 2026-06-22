<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Webhook\SsrfGuard;

final class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new SsrfGuard();
    }

    // ── Blocked private/special IPs ──

    /** @dataProvider blockedIpProvider */
    public function test_blocks_private_and_special_ips(string $url): void
    {
        $result = $this->guard->validate($url);

        $this->assertFalse($result['safe'], "Expected $url to be blocked");
    }

    public static function blockedIpProvider(): iterable
    {
        yield 'localhost'          => ['https://127.0.0.1/hook'];
        yield 'loopback range'     => ['https://127.0.0.2/hook'];
        yield '10.x private'       => ['https://10.0.0.1/hook'];
        yield '172.16 private'     => ['https://172.16.0.1/hook'];
        yield '192.168 private'    => ['https://192.168.1.1/hook'];
        yield 'link-local'         => ['https://169.254.1.1/hook'];
        yield 'metadata endpoint'  => ['https://169.254.169.254/latest/meta-data'];
        yield 'zero network'       => ['https://0.0.0.0/hook'];
        yield 'CGNAT'              => ['https://100.64.0.1/hook'];
        yield 'doc range 192.0.2'  => ['https://192.0.2.1/hook'];
        yield 'doc range 198.51'   => ['https://198.51.100.1/hook'];
        yield 'doc range 203.0'    => ['https://203.0.113.1/hook'];
    }

    // ── Blocked schemes ──

    public function test_blocks_http_scheme(): void
    {
        $result = $this->guard->validate('http://example.com/hook');

        $this->assertFalse($result['safe']);
        $this->assertSame('only HTTPS is allowed', $result['reason']);
    }

    public function test_blocks_ftp_scheme(): void
    {
        $result = $this->guard->validate('ftp://example.com/file');

        $this->assertFalse($result['safe']);
    }

    public function test_blocks_file_scheme(): void
    {
        $result = $this->guard->validate('file:///etc/passwd');

        $this->assertFalse($result['safe']);
    }

    // ── Blocked ports ──

    public function test_blocks_ssh_port(): void
    {
        $result = $this->guard->validate('https://example.com:22/hook');

        $this->assertFalse($result['safe']);
        $this->assertSame('blocked port', $result['reason']);
    }

    public function test_blocks_database_ports(): void
    {
        foreach ([3306, 5432, 6379, 27017] as $port) {
            $result = $this->guard->validate("https://example.com:{$port}/hook");

            $this->assertFalse($result['safe'], "Port $port should be blocked");
        }
    }

    // ── Invalid URLs ──

    public function test_blocks_invalid_url(): void
    {
        $result = $this->guard->validate('not-a-url');

        $this->assertFalse($result['safe']);
    }

    public function test_blocks_empty_url(): void
    {
        $result = $this->guard->validate('');

        $this->assertFalse($result['safe']);
    }

    // ── Valid external URLs ──

    public function test_allows_valid_external_https_url(): void
    {
        // Use a well-known public IP to avoid DNS dependency in tests
        $result = $this->guard->validate('https://1.1.1.1/webhook');

        $this->assertTrue($result['safe']);
        $this->assertNull($result['reason']);
        $this->assertSame('1.1.1.1', $result['resolved_ip']);
    }

    public function test_allows_default_https_port(): void
    {
        $result = $this->guard->validate('https://1.1.1.1:443/webhook');

        $this->assertTrue($result['safe']);
    }

    // ── IPv6 ──

    public function test_blocks_ipv6_loopback(): void
    {
        $result = $this->guard->validate('https://[::1]/hook');

        $this->assertFalse($result['safe']);
    }

    // ── validateIp ──

    public function test_validate_ip_blocks_private(): void
    {
        $this->assertFalse($this->guard->validateIp('10.0.0.1'));
        $this->assertFalse($this->guard->validateIp('192.168.1.1'));
        $this->assertFalse($this->guard->validateIp('127.0.0.1'));
    }

    public function test_validate_ip_allows_public(): void
    {
        $this->assertTrue($this->guard->validateIp('8.8.8.8'));
        $this->assertTrue($this->guard->validateIp('1.1.1.1'));
    }
}
