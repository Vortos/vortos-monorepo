<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\WorkerHealthcheck;

final class WorkerHealthcheckTest extends TestCase
{
    public function test_disabled_renders_compose_disable(): void
    {
        $this->assertSame(['disable' => true], WorkerHealthcheck::disabled()->toArray());
    }

    public function test_command_renders_full_healthcheck_map(): void
    {
        $hc = WorkerHealthcheck::command(
            test: ['CMD', 'true'],
            interval: '15s',
            timeout: '3s',
            retries: 5,
            startPeriod: '10s',
        );

        $this->assertSame([
            'test' => ['CMD', 'true'],
            'interval' => '15s',
            'timeout' => '3s',
            'retries' => 5,
            'start_period' => '10s',
        ], $hc->toArray());
    }

    public function test_supervisord_default_is_a_robust_variable_free_check(): void
    {
        $array = WorkerHealthcheck::supervisord()->toArray();
        $script = $array['test'][1];

        $this->assertSame('CMD-SHELL', $array['test'][0]);
        $this->assertStringContainsString('supervisorctl -c /etc/supervisord.conf status', $script);
        // Requires at least one RUNNING program (also proves supervisord is reachable)...
        $this->assertStringContainsString('grep -qE "\bRUNNING\b"', $script);
        // ...and fails ONLY on genuinely-crashed states — not on any non-RUNNING line.
        $this->assertStringContainsString('! ', $script);
        $this->assertStringContainsString('grep -qE "\b(FATAL|BACKOFF|UNKNOWN)\b"', $script);
        // The brittle "any non-RUNNING line = unhealthy" form must be gone.
        $this->assertStringNotContainsString('grep -qvE', $script);
        // No shell variables / command substitution: Docker Compose would eat the '$' during its own
        // interpolation of the compose file, silently reducing the probe to an empty-string check.
        $this->assertStringNotContainsString('$(', $script);
        $this->assertStringNotContainsString('$S', $script);
        $this->assertStringNotContainsString('`', $script);
        $this->assertSame(3, $array['retries']);
    }

    public function test_supervisord_honors_custom_config_path(): void
    {
        $array = WorkerHealthcheck::supervisord('/opt/app/supervisord.conf')->toArray();

        $this->assertStringContainsString('supervisorctl -c /opt/app/supervisord.conf status', $array['test'][1]);
    }

    public function test_disabled_with_test_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerHealthcheck(disabled: true, test: ['CMD', 'true']);
    }

    public function test_enabled_without_test_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WorkerHealthcheck(disabled: false, test: []);
    }

    public function test_invalid_test_form_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkerHealthcheck::command(test: ['SHELL', 'true']);
    }

    public function test_invalid_duration_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkerHealthcheck::command(test: ['CMD', 'true'], interval: '30seconds');
    }

    public function test_zero_retries_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkerHealthcheck::command(test: ['CMD', 'true'], retries: 0);
    }
}
