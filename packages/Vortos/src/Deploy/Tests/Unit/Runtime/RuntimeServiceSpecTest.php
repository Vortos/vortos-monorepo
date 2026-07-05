<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;

final class RuntimeServiceSpecTest extends TestCase
{
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
        ], $spec->toArray());
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
