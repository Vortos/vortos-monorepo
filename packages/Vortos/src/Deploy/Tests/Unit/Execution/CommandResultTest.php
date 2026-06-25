<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\CommandFailedException;
use Vortos\Deploy\Execution\CommandResult;

final class CommandResultTest extends TestCase
{
    public function test_success_result(): void
    {
        $result = new CommandResult(0, 'output', '', 0.5);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, $result->exitCode);
        $this->assertSame('output', $result->stdout);
    }

    public function test_failure_result(): void
    {
        $result = new CommandResult(1, '', 'error', 0.5);
        $this->assertFalse($result->isSuccess());
    }

    public function test_throw_on_failure_succeeds_for_zero_exit(): void
    {
        $result = new CommandResult(0, 'ok', '', 0.1);
        $this->assertSame($result, $result->throwOnFailure());
    }

    public function test_throw_on_failure_throws_for_nonzero_exit(): void
    {
        $result = new CommandResult(127, '', 'not found', 0.1);

        $this->expectException(CommandFailedException::class);
        $result->throwOnFailure('test');
    }

    public function test_redaction(): void
    {
        $secret = 'my-super-secret-token';
        $result = new CommandResult(
            0,
            "Login with {$secret} succeeded",
            "Warning: {$secret} exposed",
            0.1,
            [$secret],
        );

        $this->assertStringNotContainsString($secret, $result->redactedStdout());
        $this->assertStringNotContainsString($secret, $result->redactedStderr());
        $this->assertStringContainsString('***', $result->redactedStdout());
        $this->assertStringContainsString('***', $result->redactedStderr());
    }

    public function test_to_array_uses_redacted_output(): void
    {
        $secret = 'token123';
        $result = new CommandResult(0, "auth {$secret}", '', 0.1, [$secret]);
        $array = $result->toArray();

        $this->assertStringNotContainsString($secret, $array['stdout']);
    }

    public function test_no_redaction_without_tokens(): void
    {
        $result = new CommandResult(0, 'plain output', 'plain error', 0.1);
        $this->assertSame('plain output', $result->redactedStdout());
        $this->assertSame('plain error', $result->redactedStderr());
    }
}
