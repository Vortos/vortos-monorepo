<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ListOutboxCommand;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Outbox\OutboxMessage;

final class ListOutboxCommandTest extends TestCase
{
    private function makeMessage(string $status = 'pending', string $payloadType = 'App\\Event\\UserRegistered'): OutboxMessage
    {
        return new OutboxMessage(
            id:               'uuid-1234',
            transportName:    'user.events',
            eventId:          'evt-001',
            aggregateId:      'agg-001-agg-001-agg-001',
            aggregateType:    'App\\User\\Domain\\Entity\\User',
            aggregateVersion: 1,
            payloadType:      $payloadType,
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable('2026-05-22 10:00:00'),
            correlationId:    'corr-001',
            causationId:      null,
            traceId:          null,
            metadata:         [],
            payload:          '{"email":"alice@example.com"}',
            status:           $status,
            attemptCount:     0,
            createdAt:        new DateTimeImmutable('2026-05-22 10:00:00'),
            publishedAt:      null,
            nextAttemptAt:    null,
            failureReason:    null,
        );
    }

    private function makeTester(array $returnRows): CommandTester
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('query')->willReturn($returnRows);

        return new CommandTester(new ListOutboxCommand($poller));
    }

    public function test_shows_no_rows_message_when_empty(): void
    {
        $tester = $this->makeTester([]);
        $tester->execute([]);

        $this->assertStringContainsString('No outbox rows found.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_row_count(): void
    {
        $tester = $this->makeTester([$this->makeMessage(), $this->makeMessage()]);
        $tester->execute([]);

        $this->assertStringContainsString('Found 2 outbox row(s)', $tester->getDisplay());
    }

    public function test_shows_event_short_name(): void
    {
        $tester = $this->makeTester([$this->makeMessage(payloadType: 'App\\User\\Domain\\Event\\UserRegistered')]);
        $tester->execute([]);

        $this->assertStringContainsString('UserRegistered', $tester->getDisplay());
    }

    public function test_shows_transport_name(): void
    {
        $tester = $this->makeTester([$this->makeMessage()]);
        $tester->execute([]);

        $this->assertStringContainsString('user.events', $tester->getDisplay());
    }

    public function test_shows_failure_reason_for_failed_rows(): void
    {
        $msg = new OutboxMessage(
            id: 'uuid-1', transportName: 'user.events', eventId: 'e1',
            aggregateId: 'a1', aggregateType: 'T', aggregateVersion: 1,
            payloadType: 'App\\Event\\UserRegistered', schemaVersion: 1,
            occurredAt: new DateTimeImmutable(), correlationId: null,
            causationId: null, traceId: null, metadata: [],
            payload: '{}', status: 'failed', attemptCount: 5,
            createdAt: new DateTimeImmutable(), publishedAt: null,
            nextAttemptAt: null, failureReason: 'Connection refused',
        );

        $tester = $this->makeTester([$msg]);
        $tester->execute([]);

        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    public function test_invalid_status_returns_invalid_code(): void
    {
        $tester = $this->makeTester([]);
        $tester->execute(['--status' => 'unknown']);

        $this->assertSame(2, $tester->getStatusCode());
    }

    public function test_passes_status_filter_to_poller(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->expects($this->once())
            ->method('query')
            ->with('failed', null, null, 50, false, null, null)
            ->willReturn([]);

        $tester = new CommandTester(new ListOutboxCommand($poller));
        $tester->execute(['--status' => 'failed']);
    }

    public function test_passes_transport_filter_to_poller(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->expects($this->once())
            ->method('query')
            ->with(null, 'user.events', null, 50, false, null, null)
            ->willReturn([]);

        $tester = new CommandTester(new ListOutboxCommand($poller));
        $tester->execute(['--transport' => 'user.events']);
    }

    public function test_latest_flag_passes_order_desc(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->expects($this->once())
            ->method('query')
            ->with(null, null, null, 50, true, null, null)
            ->willReturn([]);

        $tester = new CommandTester(new ListOutboxCommand($poller));
        $tester->execute(['--latest' => true]);
    }
}
