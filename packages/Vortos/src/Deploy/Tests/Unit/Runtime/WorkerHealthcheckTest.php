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

    public function test_supervisord_default_is_a_robust_single_snapshot_check(): void
    {
        $array = WorkerHealthcheck::supervisord()->toArray();
        $script = $array['test'][1];

        $this->assertSame('CMD-SHELL', $array['test'][0]);
        $this->assertStringContainsString('supervisorctl -c /etc/supervisord.conf status', $script);
        // Requires at least one RUNNING program (also proves supervisord is reachable).
        $this->assertStringContainsString('grep -qE "\bRUNNING\b" || exit 1', $script);
        // Fails ONLY on genuinely-crashed states — not on any non-RUNNING line.
        $this->assertStringContainsString('grep -qE "\b(FATAL|BACKOFF|UNKNOWN)\b" && exit 1', $script);
        // Single snapshot: supervisorctl status must be invoked exactly once (no racy double-run).
        $this->assertSame(1, substr_count($script, 'supervisorctl -c'));
        // The brittle "any non-RUNNING line = unhealthy" form must be gone.
        $this->assertStringNotContainsString('grep -qvE', $script);
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
