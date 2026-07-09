<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Execution\LocalTransport;
use Vortos\Deploy\Execution\RemoteCommand;

final class LocalTransportTest extends TestCase
{
    public function testRunDelegatesArgvAndStdinToLocalRunner(): void
    {
        $runner = new class implements CommandRunnerInterface {
            /** @var list<string> */
            public array $argv = [];
            public ?string $stdin = null;

            public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
            {
                $this->argv = $argv;
                $this->stdin = $stdin;

                return new CommandResult(0, 'ok', '', 0.0);
            }
        };

        $result = (new LocalTransport($runner))->run(new RemoteCommand(['docker', 'compose', 'up'], stdin: 'payload'));

        self::assertSame(['docker', 'compose', 'up'], $runner->argv);
        self::assertSame('payload', $runner->stdin);
        self::assertSame(0, $result->exitCode);
    }

    public function testCopyWritesLocallyWithMode(): void
    {
        $runner = $this->nullRunner();
        $dir = sys_get_temp_dir() . '/vortos-local-transport-' . bin2hex(random_bytes(4));
        $src = $dir . '/src';
        $dst = $dir . '/nested/dst';
        mkdir($dir, 0755, true);
        file_put_contents($src, 'contents');

        (new LocalTransport($runner))->copy($src, $dst, '0640');

        self::assertFileExists($dst);
        self::assertSame('contents', file_get_contents($dst));
        self::assertSame('0640', substr(sprintf('%o', fileperms($dst)), -4));

        @unlink($dst);
        @unlink($src);
        @rmdir($dir . '/nested');
        @rmdir($dir);
    }

    public function testForwardIsANoOpReturningTheSamePort(): void
    {
        $transport = new LocalTransport($this->nullRunner());

        self::assertSame(2019, $transport->openLocalForward(2019));
        $transport->closeLocalForward(2019, 2019); // must not throw
        $this->addToAssertionCount(1);
    }

    private function nullRunner(): CommandRunnerInterface
    {
        return new class implements CommandRunnerInterface {
            public function run(array $argv, ?string $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
            {
                return new CommandResult(0, '', '', 0.0);
            }
        };
    }
}
