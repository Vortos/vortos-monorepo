<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Command;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ShowOutboxCommand;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Outbox\OutboxMessage;

final class ShowOutboxCommandTest extends TestCase
{
    private function makeMessage(array $overrides = []): OutboxMessage
    {
        return new OutboxMessage(
            id:               $overrides['id'] ?? 'uuid-1234',
            transportName:    $overrides['transportName'] ?? 'user.events',
            eventId:          'evt-001',
            aggregateId:      'agg-001-long-id-here',
            aggregateType:    'App\\User\\Domain\\Entity\\User',
            aggregateVersion: 3,
            payloadType:      'App\\User\\Domain\\Event\\UserRegistered',
            schemaVersion:    1,
            occurredAt:       new DateTimeImmutable('2026-05-22 10:00:00'),
            correlationId:    'corr-001',
            causationId:      'caus-001',
            traceId:          'trace-001',
            metadata:         ['tenantId' => 'tenant-a'],
            payload:          '{"email":"alice@example.com"}',
            status:           $overrides['status'] ?? 'pending',
            attemptCount:     $overrides['attemptCount'] ?? 0,
            createdAt:        new DateTimeImmutable('2026-05-22 10:00:00'),
            publishedAt:      $overrides['publishedAt'] ?? null,
            nextAttemptAt:    null,
            failureReason:    $overrides['failureReason'] ?? null,
        );
    }

    public function test_shows_not_found_when_id_missing(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('findById')->willReturn(null);

        $tester = new CommandTester(new ShowOutboxCommand($poller));
        $tester->execute(['id' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_shows_all_core_fields(): void
    {
        $msg    = $this->makeMessage();
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('findById')->willReturn($msg);

        $tester = new CommandTester(new ShowOutboxCommand($poller));
        $tester->execute(['id' => 'uuid-1234']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('uuid-1234', $display);
        $this->assertStringContainsString('user.events', $display);
        $this->assertStringContainsString('UserRegistered', $display);
        $this->assertStringContainsString('corr-001', $display);
        $this->assertStringContainsString('caus-001', $display);
        $this->assertStringContainsString('trace-001', $display);
        $this->assertStringContainsString('tenantId', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_payload_formatted(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('findById')->willReturn($this->makeMessage());

        $tester = new CommandTester(new ShowOutboxCommand($poller));
        $tester->execute(['id' => 'uuid-1234']);

        $this->assertStringContainsString('alice@example.com', $tester->getDisplay());
    }

    public function test_shows_failure_reason_when_failed(): void
    {
        $msg    = $this->makeMessage(['status' => 'failed', 'failureReason' => 'DB timeout']);
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('findById')->willReturn($msg);

        $tester = new CommandTester(new ShowOutboxCommand($poller));
        $tester->execute(['id' => 'uuid-1234']);

        $this->assertStringContainsString('DB timeout', $tester->getDisplay());
    }

    public function test_shows_published_at_when_set(): void
    {
        $msg    = $this->makeMessage(['status' => 'published', 'publishedAt' => new DateTimeImmutable('2026-05-22 10:01:00')]);
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->method('findById')->willReturn($msg);

        $tester = new CommandTester(new ShowOutboxCommand($poller));
        $tester->execute(['id' => 'uuid-1234']);

        $this->assertStringContainsString('2026-05-22 10:01:00', $tester->getDisplay());
    }
}
