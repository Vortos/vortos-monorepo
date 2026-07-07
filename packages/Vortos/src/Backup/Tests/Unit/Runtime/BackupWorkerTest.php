<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Runtime\BackupLifecycleRunnerInterface;
use Vortos\Backup\Runtime\BackupWorker;
use Vortos\Backup\Runtime\InMemoryScheduleStateStore;
use Vortos\Backup\Schedule\BackupSchedule;
use Vortos\Backup\Schedule\BackupScheduleType;

final class BackupWorkerTest extends TestCase
{
    public function test_fires_a_due_schedule_and_rebases_watermark(): void
    {
        $runner = new RecordingLifecycleRunner();
        $state = new InMemoryScheduleStateStore();
        $worker = new BackupWorker(
            [$this->schedule('0 */6 * * *')],
            $runner,
            $state,
            $this->clock('2024-01-01 06:00:00'),
            startedAt: $this->at('2024-01-01 05:59:00'),
        );

        $log = $worker->tick($this->at('2024-01-01 06:00:00'));

        $this->assertCount(1, $runner->executed);
        $this->assertStringContainsString('fired', $log[0]['result']);
        // Re-based: not due again at the same minute.
        $again = $worker->tick($this->at('2024-01-01 06:00:00'));
        $this->assertSame([], $again);
        $this->assertCount(1, $runner->executed, 'must not double-fire within the same window');
    }

    public function test_not_fired_before_due(): void
    {
        $runner = new RecordingLifecycleRunner();
        $worker = new BackupWorker(
            [$this->schedule('0 */6 * * *')],
            $runner,
            new InMemoryScheduleStateStore(),
            $this->clock('2024-01-01 05:30:00'),
            startedAt: $this->at('2024-01-01 05:00:00'),
        );

        $worker->tick($this->at('2024-01-01 05:30:00'));

        $this->assertCount(0, $runner->executed);
    }

    public function test_restart_does_not_double_fire_via_persisted_watermark(): void
    {
        $runner = new RecordingLifecycleRunner();
        $state = new InMemoryScheduleStateStore(); // shared across "restarts"

        $w1 = new BackupWorker([$this->schedule('0 */6 * * *')], $runner, $state, $this->clock('2024-01-01 06:00:00'), startedAt: $this->at('2024-01-01 05:00:00'));
        $w1->tick($this->at('2024-01-01 06:00:00'));

        // "Restart": new worker, same store, same minute — must not re-fire.
        $w2 = new BackupWorker([$this->schedule('0 */6 * * *')], $runner, $state, $this->clock('2024-01-01 06:00:00'), startedAt: $this->at('2024-01-01 06:00:00'));
        $w2->tick($this->at('2024-01-01 06:00:00'));

        $this->assertCount(1, $runner->executed);
    }

    public function test_failure_backs_off_then_retries(): void
    {
        $runner = new RecordingLifecycleRunner(failTimes: 1);
        $state = new InMemoryScheduleStateStore();
        $worker = new BackupWorker(
            [$this->schedule('0 * * * *')],
            $runner,
            $state,
            $this->clock('2024-01-01 06:00:00'),
            baseBackoffSeconds: 30,
            startedAt: $this->at('2024-01-01 05:59:00'),
        );

        // First tick: fails, schedules a retry.
        $log = $worker->tick($this->at('2024-01-01 06:00:00'));
        $this->assertStringContainsString('failed (attempt 1', $log[0]['result']);

        // Within the backoff window: skipped.
        $this->assertSame([], $worker->tick($this->at('2024-01-01 06:00:10')));

        // After backoff: retried and succeeds.
        $log = $worker->tick($this->at('2024-01-01 06:00:40'));
        $this->assertStringContainsString('fired', $log[0]['result']);
        $this->assertSame(2, $runner->calls);
    }

    public function test_exhausted_retries_emit_a_failure_alert_and_give_up(): void
    {
        $runner = new RecordingLifecycleRunner(failTimes: 100);
        $events = new RecordingEventSink();
        $worker = new BackupWorker(
            [$this->schedule('0 * * * *')],
            $runner,
            new InMemoryScheduleStateStore(),
            $this->clock('2024-01-01 06:00:00'),
            events: $events,
            maxRetries: 3,
            baseBackoffSeconds: 1,
            startedAt: $this->at('2024-01-01 05:59:00'),
        );

        // Drive ticks past the backoff windows until retries exhaust.
        $t = $this->at('2024-01-01 06:00:00');
        for ($i = 0; $i < 3; $i++) {
            $worker->tick($t);
            $t = $t->modify('+10 minutes');
        }

        $this->assertNotEmpty($events->events);
        $this->assertSame(BackupEvent::TYPE_FAILED, $events->events[array_key_last($events->events)]->type);
    }

    private function schedule(string $cron): BackupSchedule
    {
        return new BackupSchedule('nightly', DatabaseEngine::Postgres, BackupKind::LogicalFull, 'production', $cron, BackupScheduleType::Backup);
    }

    private function clock(string $s): ClockInterface
    {
        return new class($this->at($s)) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now) {}
            public function now(): DateTimeImmutable { return $this->now; }
        };
    }

    private function at(string $s): DateTimeImmutable
    {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }
}

final class RecordingLifecycleRunner implements BackupLifecycleRunnerInterface
{
    /** @var list<string> */
    public array $executed = [];
    public int $calls = 0;

    public function __construct(private int $failTimes = 0) {}

    public function execute(BackupSchedule $schedule): string
    {
        $this->calls++;
        if ($this->failTimes > 0) {
            $this->failTimes--;
            throw new \RuntimeException('boom');
        }
        $this->executed[] = $schedule->name;

        return 'ok';
    }
}

final class RecordingEventSink implements BackupEventSinkInterface
{
    /** @var list<BackupEvent> */
    public array $events = [];

    public function emit(BackupEvent $event): void
    {
        $this->events[] = $event;
    }
}
