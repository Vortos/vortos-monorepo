<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\AppHealthcheck;

final class AppHealthcheckTest extends TestCase
{
    public function test_disabled_renders_compose_disable(): void
    {
        $this->assertSame(['disable' => true], AppHealthcheck::disabled()->toArray());
    }

    public function test_command_renders_full_healthcheck_map(): void
    {
        $hc = AppHealthcheck::command(
            test: ['CMD', 'true'],
            interval: '5s',
            timeout: '3s',
            retries: 5,
            startPeriod: '10s',
        );

        $this->assertSame([
            'test' => ['CMD', 'true'],
            'interval' => '5s',
            'timeout' => '3s',
            'retries' => 5,
            'start_period' => '10s',
        ], $hc->toArray());
    }

    public function test_http_readiness_default_curls_health_ready_on_loopback_port(): void
    {
        $array = AppHealthcheck::httpReadiness(8080)->toArray();

        $this->assertSame('CMD-SHELL', $array['test'][0]);
        $this->assertStringContainsString('curl', $array['test'][1]);
        $this->assertStringContainsString('http://127.0.0.1:8080/health/ready', $array['test'][1]);
        // A ~70s window (10s start-period + 20×3s) — wider than the deploy readiness gate.
        $this->assertSame(20, $array['retries']);
        $this->assertSame('10s', $array['start_period']);
    }

    public function test_http_readiness_honors_custom_port_and_path(): void
    {
        $array = AppHealthcheck::httpReadiness(9000, '/status/ready')->toArray();

        $this->assertStringContainsString('http://127.0.0.1:9000/status/ready', $array['test'][1]);
    }

    public function test_disabled_with_test_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppHealthcheck(disabled: true, test: ['CMD', 'true']);
    }

    public function test_enabled_without_test_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppHealthcheck(disabled: false, test: []);
    }

    public function test_invalid_test_form_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppHealthcheck::command(test: ['SHELL', 'true']);
    }

    public function test_invalid_duration_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppHealthcheck::command(test: ['CMD', 'true'], interval: '3seconds');
    }

    public function test_zero_retries_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppHealthcheck::command(test: ['CMD', 'true'], retries: 0);
    }
}
