<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\RemoteCommand;

final class RemoteCommandTest extends TestCase
{
    public function test_construction(): void
    {
        $cmd = new RemoteCommand(['docker', 'ps'], 'input', '/app');

        $this->assertSame(['docker', 'ps'], $cmd->argv);
        $this->assertSame('input', $cmd->stdin);
        $this->assertSame('/app', $cmd->workingDir);
    }

    public function test_rejects_empty_argv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RemoteCommand([]);
    }

    public function test_optional_fields_default_to_null(): void
    {
        $cmd = new RemoteCommand(['ls']);

        $this->assertNull($cmd->stdin);
        $this->assertNull($cmd->workingDir);
    }
}
