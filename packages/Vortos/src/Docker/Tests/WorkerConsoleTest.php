<?php

declare(strict_types=1);

namespace Vortos\Docker\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Docker\Worker\WorkerConsole;

/**
 * Supervisor programs run with no guaranteed cwd, so a relative console path installs a program that
 * dies on start. Package-registered worker commands must always be absolute.
 */
final class WorkerConsoleTest extends TestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = $_ENV['VORTOS_WORKER_CONSOLE_BIN'] ?? null;
        unset($_ENV['VORTOS_WORKER_CONSOLE_BIN']);
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['VORTOS_WORKER_CONSOLE_BIN']);
        } else {
            $_ENV['VORTOS_WORKER_CONSOLE_BIN'] = $this->previous;
        }
    }

    public function test_default_command_is_absolute(): void
    {
        $command = WorkerConsole::command('vortos:alerts:drain --loop');

        self::assertSame('php /var/www/html/bin/console vortos:alerts:drain --loop', $command);
        self::assertStringStartsWith('/', WorkerConsole::bin());
    }

    public function test_image_layout_can_be_overridden(): void
    {
        $_ENV['VORTOS_WORKER_CONSOLE_BIN'] = '/app/bin/console';

        self::assertSame('php /app/bin/console vortos:outbox:relay', WorkerConsole::command('vortos:outbox:relay'));
    }

    public function test_blank_override_falls_back_to_the_default(): void
    {
        $_ENV['VORTOS_WORKER_CONSOLE_BIN'] = '   ';

        self::assertSame(WorkerConsole::DEFAULT_BIN, WorkerConsole::bin());
    }
}
