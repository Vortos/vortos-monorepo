<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\ProcessCommandRunner;

final class ProcessCommandRunnerTest extends TestCase
{
    public function test_runs_simple_command(): void
    {
        $runner = new ProcessCommandRunner();
        $result = $runner->run(['echo', 'hello']);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('hello', $result->stdout);
    }

    public function test_captures_exit_code(): void
    {
        $runner = new ProcessCommandRunner();
        $result = $runner->run(['bash', '-c', 'exit 42']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(42, $result->exitCode);
    }

    public function test_rejects_empty_argv(): void
    {
        $runner = new ProcessCommandRunner();

        $this->expectException(\InvalidArgumentException::class);
        $runner->run([]);
    }

    public function test_passes_stdin(): void
    {
        $runner = new ProcessCommandRunner();
        $result = $runner->run(['cat'], stdin: 'hello from stdin');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('hello from stdin', $result->stdout);
    }

    public function test_records_duration(): void
    {
        $runner = new ProcessCommandRunner();
        $result = $runner->run(['echo', 'fast']);

        $this->assertGreaterThan(0.0, $result->duration);
    }

    public function test_captures_stderr(): void
    {
        $runner = new ProcessCommandRunner();
        $result = $runner->run(['bash', '-c', 'echo error >&2']);

        $this->assertStringContainsString('error', $result->stderr);
    }
}
