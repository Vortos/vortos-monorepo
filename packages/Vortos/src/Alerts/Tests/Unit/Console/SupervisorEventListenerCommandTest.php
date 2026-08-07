<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Console\SupervisorEventListenerCommand;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Severity;

/**
 * Drives the listener with the bytes supervisord actually sends.
 *
 * The protocol is the whole risk here. A listener that parses the header slightly wrong does not
 * throw — it silently stops recognising events, and the failure mode is indistinguishable from
 * "nothing has gone wrong yet", which is precisely the state this command exists to disprove.
 */
final class SupervisorEventListenerCommandTest extends TestCase
{
    public function test_fatal_event_raises_a_critical_alert(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->drive($dispatcher, [
            $this->event('PROCESS_STATE_FATAL', 'processname:backup-worker groupname:backup-worker from_state:BACKOFF'),
        ]);

        self::assertCount(1, $dispatcher->events);
        self::assertSame(Severity::Critical, $dispatcher->events[0]->severity);
        self::assertStringContainsString('backup-worker', $dispatcher->events[0]->title);
        self::assertSame('backup-worker', $dispatcher->events[0]->labels['process']);
        self::assertSame('BACKOFF', $dispatcher->events[0]->annotations['from_state']);
    }

    /**
     * The states supervisord passes through on every ordinary restart must stay silent. A listener
     * that alerts on these gets muted within a day, and then the FATAL alert is muted with it.
     */
    public function test_ordinary_lifecycle_events_are_silent(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->drive($dispatcher, [
            $this->event('PROCESS_STATE_RUNNING', 'processname:backup-worker groupname:backup-worker from_state:STARTING'),
            $this->event('PROCESS_STATE_EXITED', 'processname:backup-worker groupname:backup-worker from_state:RUNNING'),
            $this->event('PROCESS_STATE_BACKOFF', 'processname:backup-worker groupname:backup-worker from_state:STARTING'),
            $this->event('TICK_60', 'when:1780000000'),
        ]);

        self::assertSame([], $dispatcher->events);
    }

    /** Every event is acknowledged, including ones that raise nothing — supervisord waits for it. */
    public function test_every_event_is_acknowledged(): void
    {
        $dispatcher = new RecordingDispatcher();

        $output = $this->drive($dispatcher, [
            $this->event('PROCESS_STATE_FATAL', 'processname:a groupname:a from_state:BACKOFF'),
            $this->event('PROCESS_STATE_RUNNING', 'processname:b groupname:b from_state:STARTING'),
        ]);

        self::assertSame(2, substr_count($output, 'RESULT 2'));
        // One READY per event. There is no trailing one only because --max-events ends the loop
        // after the last ACK; unbounded, the next iteration announces READY and blocks on stdin.
        self::assertSame(2, substr_count($output, 'READY'));
        self::assertStringStartsWith('READY', $output, 'The listener must announce itself before reading.');
    }

    /**
     * A broken alert backend must not take the listener down with it, and must not stall the
     * protocol: the event is still acknowledged so supervisord does not requeue it forever.
     */
    public function test_a_failing_dispatch_still_acknowledges_the_event(): void
    {
        $dispatcher = new class implements AlertDispatcherInterface {
            public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
            {
                throw new \RuntimeException('alert backend down');
            }
        };

        $output = $this->drive($dispatcher, [
            $this->event('PROCESS_STATE_FATAL', 'processname:backup-worker groupname:backup-worker from_state:BACKOFF'),
        ]);

        self::assertStringContainsString('RESULT 2', $output);
    }

    /** @param list<string> $events */
    private function drive(AlertDispatcherInterface $dispatcher, array $events): string
    {
        $stdin = fopen('php://memory', 'r+b');
        $stdout = fopen('php://memory', 'r+b');
        $stderr = fopen('php://memory', 'r+b');
        self::assertIsResource($stdin);
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        fwrite($stdin, implode('', $events));
        rewind($stdin);

        $command = new SupervisorEventListenerCommand($dispatcher, $stdin, $stdout, $stderr);
        $tester = new CommandTester($command);
        $tester->execute([
            '--env' => 'testing',
            '--node' => 'node-1',
            '--max-events' => (string) count($events),
        ]);

        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }

    private function event(string $name, string $payload): string
    {
        return sprintf(
            "ver:3.0 server:supervisor serial:1 pool:listener poolserial:1 eventname:%s len:%d\n%s",
            $name,
            strlen($payload),
            $payload,
        );
    }
}

/** @internal */
final class RecordingDispatcher implements AlertDispatcherInterface
{
    /** @var list<AlertEvent> */
    public array $events = [];

    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
    {
        $this->events[] = $event;

        return new DispatchResult(DedupeDecision::New, []);
    }
}
