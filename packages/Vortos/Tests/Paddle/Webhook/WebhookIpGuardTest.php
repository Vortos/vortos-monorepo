<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Webhook;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Exception\WebhookIpException;
use Vortos\Paddle\Webhook\KnownPaddleIps;
use Vortos\Paddle\Webhook\WebhookIpGuard;

final class WebhookIpGuardTest extends TestCase
{
    public function test_guard_disabled_allows_any_ip(): void
    {
        $guard = new WebhookIpGuard(enabled: false, allowSandboxIps: false);
        $guard->check('1.2.3.4');
        $this->assertTrue(true);
    }

    public function test_live_ip_passes_when_enabled(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: false);
        $guard->check(KnownPaddleIps::LIVE[0]);
        $this->assertTrue(true);
    }

    public function test_unknown_ip_blocked_when_enabled(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: false);
        $this->expectException(WebhookIpException::class);
        $guard->check('1.2.3.4');
    }

    public function test_sandbox_ip_blocked_when_allow_sandbox_ips_false(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: false);
        $this->expectException(WebhookIpException::class);
        $guard->check(KnownPaddleIps::SANDBOX[0]);
    }

    public function test_sandbox_ip_allowed_when_allow_sandbox_ips_true(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: true);
        $guard->check(KnownPaddleIps::SANDBOX[0]);
        $this->assertTrue(true);
    }

    public function test_all_live_ips_pass(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: false);
        foreach (KnownPaddleIps::LIVE as $ip) {
            $guard->check($ip);
        }
        $this->assertCount(6, KnownPaddleIps::LIVE);
    }

    public function test_all_sandbox_ips_pass_when_enabled(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: true);
        foreach (KnownPaddleIps::SANDBOX as $ip) {
            $guard->check($ip);
        }
        $this->assertCount(6, KnownPaddleIps::SANDBOX);
    }

    public function test_exception_message_contains_ip(): void
    {
        $guard = new WebhookIpGuard(enabled: true, allowSandboxIps: false);
        try {
            $guard->check('9.8.7.6');
            $this->fail('Expected WebhookIpException');
        } catch (WebhookIpException $e) {
            $this->assertStringContainsString('9.8.7.6', $e->getMessage());
        }
    }
}
