<?php

declare(strict_types=1);

namespace Vortos\Metrics\Tests\Supervisor;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Supervisor\SupervisorCommandRunnerInterface;
use Vortos\Metrics\Supervisor\SupervisorStatusReader;

final class SupervisorStatusReaderTest extends TestCase
{
    public function test_parses_a_running_program(): void
    {
        $statuses = $this->reader()->parse(
            'consumer-entry-approved          RUNNING   pid 123, uptime 0:10:23',
        );

        self::assertCount(1, $statuses);
        self::assertSame('consumer-entry-approved', $statuses[0]->program);
        self::assertSame('RUNNING', $statuses[0]->state);
        self::assertSame(123, $statuses[0]->pid);
        self::assertSame(623, $statuses[0]->uptimeSeconds);
        self::assertTrue($statuses[0]->isRunning());
    }

    public function test_parses_a_dead_program_with_no_pid_or_uptime(): void
    {
        $statuses = $this->reader()->parse(
            'scheduler-daemon                 FATAL     Exited too quickly (process log may have details)',
        );

        self::assertSame('FATAL', $statuses[0]->state);
        self::assertNull($statuses[0]->pid);
        self::assertNull($statuses[0]->uptimeSeconds);
        self::assertFalse($statuses[0]->isRunning());
    }

    public function test_uptime_hours_are_unbounded_because_supervisor_rolls_days_into_hours(): void
    {
        $statuses = $this->reader()->parse('worker  RUNNING   pid 9, uptime 128:04:05');

        self::assertSame((128 * 3600) + (4 * 60) + 5, $statuses[0]->uptimeSeconds);
    }

    public function test_parses_a_full_multi_program_listing(): void
    {
        $statuses = $this->reader()->parse(
            "consumer-entry-approved          RUNNING   pid 123, uptime 0:10:23\n"
            . "consumer-entry-rejected          RUNNING   pid 124, uptime 0:10:23\n"
            . "scheduler-daemon                 FATAL     Exited too quickly\n"
            . "paddle-inbox-process             STARTING",
        );

        self::assertCount(4, $statuses);
        self::assertSame(
            ['RUNNING', 'RUNNING', 'FATAL', 'STARTING'],
            array_map(static fn ($s) => $s->state, $statuses),
        );
        self::assertNull($statuses[3]->pid, 'A STARTING program has no pid line yet.');
    }

    public function test_blank_and_unparseable_lines_are_ignored(): void
    {
        $statuses = $this->reader()->parse("\n  \nunix:///tmp/supervisor.sock refused connection\n");

        self::assertSame([], $statuses);
    }

    public function test_read_shells_out_and_parses_the_result(): void
    {
        $runner = new class implements SupervisorCommandRunnerInterface {
            /** @var list<string> */
            public array $argv = [];

            public function run(array $argv, float $timeoutSeconds): string
            {
                $this->argv = $argv;

                return 'consumer-a  RUNNING   pid 7, uptime 0:00:30';
            }
        };

        $statuses = (new SupervisorStatusReader($runner, '/etc/supervisord.conf'))->read();

        self::assertSame(['supervisorctl', '-c', '/etc/supervisord.conf', 'status'], $runner->argv);
        self::assertSame('consumer-a', $statuses[0]->program);
    }

    private function reader(): SupervisorStatusReader
    {
        return new SupervisorStatusReader(new class implements SupervisorCommandRunnerInterface {
            public function run(array $argv, float $timeoutSeconds): string
            {
                throw new \LogicException('parse() must not shell out.');
            }
        });
    }
}
