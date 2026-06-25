<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\RateLimit\RateLimitFailureConfig;
use Vortos\Auth\RateLimit\RateLimitFailureMode;
use Vortos\Auth\RateLimit\RateLimitScope;

final class RateLimitFailureConfigTest extends TestCase
{
    public function test_default_ip_is_fail_closed(): void
    {
        $config = new RateLimitFailureConfig();
        $this->assertSame(RateLimitFailureMode::FailClosed, $config->modeForScope(RateLimitScope::Ip));
    }

    public function test_default_global_is_fail_closed(): void
    {
        $config = new RateLimitFailureConfig();
        $this->assertSame(RateLimitFailureMode::FailClosed, $config->modeForScope(RateLimitScope::Global));
    }

    public function test_default_user_is_fail_open(): void
    {
        $config = new RateLimitFailureConfig();
        $this->assertSame(RateLimitFailureMode::FailOpen, $config->modeForScope(RateLimitScope::User));
    }

    public function test_custom_modes(): void
    {
        $config = new RateLimitFailureConfig(
            ipMode: RateLimitFailureMode::FailOpen,
            globalMode: RateLimitFailureMode::FailOpen,
            userMode: RateLimitFailureMode::FailClosed,
        );

        $this->assertSame(RateLimitFailureMode::FailOpen, $config->modeForScope(RateLimitScope::Ip));
        $this->assertSame(RateLimitFailureMode::FailOpen, $config->modeForScope(RateLimitScope::Global));
        $this->assertSame(RateLimitFailureMode::FailClosed, $config->modeForScope(RateLimitScope::User));
    }

    public function test_failure_mode_enum_values(): void
    {
        $this->assertSame('fail_closed', RateLimitFailureMode::FailClosed->value);
        $this->assertSame('fail_open', RateLimitFailureMode::FailOpen->value);
    }

    public function test_failure_mode_from_string(): void
    {
        $this->assertSame(RateLimitFailureMode::FailClosed, RateLimitFailureMode::from('fail_closed'));
        $this->assertSame(RateLimitFailureMode::FailOpen, RateLimitFailureMode::from('fail_open'));
    }
}
