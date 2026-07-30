<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\ResourceLimits;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Runtime\WorkerHealthcheck;

final class RuntimeServiceSpecTest extends TestCase
{
    public function test_default_supervisord_worker_resolves_to_supervisorctl_healthcheck(): void
    {
        // GAP-G: the default worker command runs supervisord ⇒ a real supervisorctl healthcheck.
        $hc = (new RuntimeServiceSpec())->resolvedWorkerHealthcheck();

        $this->assertFalse($hc->disabled);
        $this->assertStringContainsString('supervisorctl', $hc->test[1]);
    }

    public function test_custom_worker_resolves_to_disabled_healthcheck(): void
    {
        $hc = (new RuntimeServiceSpec(workerCommand: ['php', 'bin/console', 'messenger:consume']))
            ->resolvedWorkerHealthcheck();

        $this->assertTrue($hc->disabled);
    }

    public function test_explicit_worker_healthcheck_overrides_default(): void
    {
        $override = WorkerHealthcheck::command(['CMD', 'pgrep', 'consume']);
        $spec = new RuntimeServiceSpec(workerHealthcheck: $override);

        $this->assertSame($override, $spec->resolvedWorkerHealthcheck());
    }

    public function test_defaults_match_the_shipped_frankenphp_stub(): void
    {
        $spec = new RuntimeServiceSpec();

        $this->assertContains('frankenphp', $spec->command);
        $this->assertSame(8080, $spec->containerPort);
        $this->assertSame(['/opt/vortos/.env.prod'], $spec->envFiles);
        $this->assertContains('/usr/bin/supervisord', $spec->workerCommand);
        $this->assertSame(['vortos-net'], $spec->networks);
    }

    public function test_to_array_round_trips_fields(): void
    {
        $spec = new RuntimeServiceSpec(
            command: ['frankenphp', 'run'],
            containerPort: 9000,
            envFiles: ['/opt/vortos/.env.prod'],
            workerCommand: ['php', 'bin/console', 'messenger:consume'],
            environment: ['SERVER_NAME' => ':9000'],
        );

        $this->assertSame([
            'command' => ['frankenphp', 'run'],
            'container_port' => 9000,
            'env_files' => ['/opt/vortos/.env.prod'],
            'worker_command' => ['php', 'bin/console', 'messenger:consume'],
            'environment' => ['SERVER_NAME' => ':9000'],
            'networks' => ['vortos-net'],
            'file_secrets' => [],
            // GAP-G: custom (non-supervisord) worker command ⇒ healthcheck disabled (still overrides
            // the base image's inherited HTTP HEALTHCHECK).
            'worker_healthcheck' => ['disable' => true],
            // The app defaults to an HTTP /health/ready probe on the container port — the readiness
            // signal the worker gates on so its consumer fan-out cannot race the readiness gate.
            'app_healthcheck' => [
                'test' => ['CMD-SHELL', 'curl -fsS -o /dev/null http://127.0.0.1:9000/health/ready || exit 1'],
                'interval' => '3s',
                'timeout' => '5s',
                'retries' => 20,
                'start_period' => '10s',
            ],
            // Always concrete: inheriting the daemon's stock 1024 descriptor limit is never correct
            // for a container serving connections, so there is no "unset" state to fall through to.
            'resource_limits' => ['nofile' => ['soft' => 65535, 'hard' => 65535]],
            // Single-container default: no sibling supervisor configs to consider.
            'sibling_supervisor_configs' => [],
        ], $spec->toArray());
    }

    public function test_resource_limits_default_to_a_raised_descriptor_ceiling(): void
    {
        $limits = (new RuntimeServiceSpec())->resolvedResourceLimits();

        // The point of the default is that it clears the Docker daemon's stock soft limit of 1024 by
        // a wide margin — a container serving connections that inherits 1024 refuses traffic a few
        // hundred clients in, with idle CPU and nothing on a dashboard to explain it.
        $this->assertGreaterThan(1024, $limits->nofileSoft);
        $this->assertSame($limits->nofileSoft, $limits->nofileHard);
    }

    public function test_explicit_resource_limits_override_the_default(): void
    {
        $override = ResourceLimits::nofile(20000);
        $spec = new RuntimeServiceSpec(resourceLimits: $override);

        $this->assertSame($override, $spec->resolvedResourceLimits());
    }

    /**
     * Workers are split across containers on purpose, so the doctor needs to know which other
     * supervisor configs exist to tell "placed elsewhere" from "placed nowhere". Image layout, so it
     * is declared config — never an env var, never a secret.
     */
    public function test_sibling_supervisor_configs_round_trip(): void
    {
        $spec = new RuntimeServiceSpec(siblingSupervisorConfigs: ['/etc/supervisord.scheduler.conf']);

        $this->assertSame(['/etc/supervisord.scheduler.conf'], $spec->siblingSupervisorConfigs);
        $this->assertSame(['/etc/supervisord.scheduler.conf'], $spec->toArray()['sibling_supervisor_configs']);
    }

    public function test_rejects_relative_sibling_supervisor_config(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute paths inside the image');

        new RuntimeServiceSpec(siblingSupervisorConfigs: ['docker/backup/supervisord.scheduler.conf']);
    }

    public function test_rejects_empty_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeServiceSpec(command: []);
    }

    public function test_rejects_empty_worker_command(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeServiceSpec(command: ['x'], workerCommand: []);
    }

    /** @dataProvider badPorts */
    public function test_rejects_out_of_range_port(int $port): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeServiceSpec(command: ['x'], containerPort: $port);
    }

    /** @return array<string, array{int}> */
    public static function badPorts(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'too-high' => [70000]];
    }

    public function test_rejects_relative_env_file_path(): void
    {
        // The cutover compose is written to /tmp on the target; relative env_file paths would not
        // resolve there, so they are rejected at construction.
        $this->expectException(\InvalidArgumentException::class);
        new RuntimeServiceSpec(command: ['x'], envFiles: ['.env.prod']);
    }

    public function test_rejects_non_string_command_entry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional bad input */
        new RuntimeServiceSpec(command: ['ok', '']);
    }
}
