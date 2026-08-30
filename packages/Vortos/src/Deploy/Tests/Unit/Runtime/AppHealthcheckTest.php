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
            startInterval: '1s',
        );

        $this->assertSame([
            'test' => ['CMD', 'true'],
            'interval' => '5s',
            'timeout' => '3s',
            'retries' => 5,
            'start_period' => '10s',
            'start_interval' => '1s',
        ], $hc->toArray());
    }

    public function test_http_readiness_default_curls_health_ready_on_loopback_port(): void
    {
        $array = AppHealthcheck::httpReadiness(8080)->toArray();

        $this->assertSame('CMD-SHELL', $array['test'][0]);
        $this->assertStringContainsString('curl', $array['test'][1]);
        $this->assertStringContainsString('http://127.0.0.1:8080/health/ready', $array['test'][1]);
        // Boot stays fast (3s start_interval inside the 10s start-period) so the worker's
        // service_healthy gate flips promptly; steady state settles to 30s x 5 (~2.5 min) so a
        // healthy container is not probing — nor exercising its dependencies — every few seconds.
        $this->assertSame('30s', $array['interval']);
        $this->assertSame('3s', $array['start_interval']);
        $this->assertSame(5, $array['retries']);
        $this->assertSame('10s', $array['start_period']);
    }

    public function test_start_interval_must_be_a_compose_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AppHealthcheck::command(test: ['CMD', 'true'], startInterval: '3seconds');
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
