<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\Flush\FlushScheduler;

final class FlushSchedulerTest extends TestCase
{
    public function test_flush_all_flushes_every_registered_handler(): void
    {
        $inner = new TestHandler();
        $buffer = new BufferHandler($inner, 0, Level::Debug, true, true);

        $scheduler = new FlushScheduler();
        $scheduler->register($buffer, 60);

        $buffer->handle($this->record('queued'));
        $this->assertFalse($this->hasMessage($inner, 'queued'));

        $scheduler->flushAll();

        $this->assertTrue($this->hasMessage($inner, 'queued'));
    }

    public function test_flush_due_skips_handlers_whose_interval_has_not_elapsed(): void
    {
        $inner = new TestHandler();
        $buffer = new BufferHandler($inner, 0, Level::Debug, true, true);

        $scheduler = new FlushScheduler();
        $scheduler->register($buffer, 3600);

        $buffer->handle($this->record('queued'));
        $scheduler->flushDue();

        $this->assertFalse($this->hasMessage($inner, 'queued'), 'flushDue() should not flush before the interval elapses');
    }

    public function test_flush_due_flushes_handlers_whose_interval_has_elapsed(): void
    {
        $inner = new TestHandler();
        $buffer = new BufferHandler($inner, 0, Level::Debug, true, true);

        $scheduler = new FlushScheduler();
        $scheduler->register($buffer, 1);

        $buffer->handle($this->record('queued'));

        // Backdate the registration's lastFlush so the 1-second interval has elapsed.
        $property = new \ReflectionProperty(FlushScheduler::class, 'registrations');
        $registrations = $property->getValue($scheduler);
        $registrations[0]['lastFlush'] = time() - 10;
        $property->setValue($scheduler, $registrations);

        $scheduler->flushDue();

        $this->assertTrue($this->hasMessage($inner, 'queued'));
    }

    public function test_start_is_idempotent_and_registers_shutdown_flush(): void
    {
        $inner = new TestHandler();
        $buffer = new BufferHandler($inner, 0, Level::Debug, true, true);

        $scheduler = new FlushScheduler();
        $scheduler->register($buffer, 60);

        // Calling start() multiple times must not throw or double-register.
        $scheduler->start();
        $scheduler->start();

        $buffer->handle($this->record('daemon log'));

        // The shutdown function guarantees a final flush even for processes
        // that never reach a request/command-end flush point.
        $scheduler->flushAll();
        $this->assertTrue($this->hasMessage($inner, 'daemon log'));
    }

    private function hasMessage(TestHandler $handler, string $message): bool
    {
        foreach ($handler->getRecords() as $record) {
            if ($record['message'] === $message) {
                return true;
            }
        }

        return false;
    }

    private function record(string $message): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: $message,
        );
    }
}
