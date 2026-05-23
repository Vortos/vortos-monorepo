<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging\Command;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\OutboxReplayCommand;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Outbox\OutboxMessage;

final class OutboxReplayCommandTest extends TestCase
{
    private OutboxPollerInterface&MockObject $poller;

    protected function setUp(): void
    {
        $this->poller = $this->createMock(OutboxPollerInterface::class);
    }

    private function tester(): CommandTester
    {
        $command = new OutboxReplayCommand($this->poller, new NullLogger());
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    private function makeMessage(string $id = 'msg-1'): OutboxMessage
    {
        return new OutboxMessage(
            id: $id,
            transportName: 'user.events',
            eventId: 'evt-1',
            aggregateId: 'agg-1',
            aggregateType: 'User',
            aggregateVersion: 1,
            payloadType: 'App\\User\\Domain\\Event\\UserRegistered',
            schemaVersion: 1,
            occurredAt: new DateTimeImmutable(),
            correlationId: null,
            causationId: null,
            traceId: null,
            metadata: [],
            payload: '{}',
            status: 'failed',
            attemptCount: 5,
            createdAt: new DateTimeImmutable(),
            publishedAt: null,
            nextAttemptAt: null,
            failureReason: 'connection refused',
        );
    }

    public function test_no_messages_returns_success(): void
    {
        $this->poller->method('fetchFailed')->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('No permanently failed outbox messages found.', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_dry_run_does_not_reset(): void
    {
        $this->poller->method('fetchFailed')->willReturn([$this->makeMessage()]);
        $this->poller->expects($this->never())->method('resetFailed');

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Dry run', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_force_skips_prompt_and_resets(): void
    {
        $this->poller->method('fetchFailed')->willReturn([$this->makeMessage()]);
        $this->poller->expects($this->once())->method('resetFailed')->with('msg-1');

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertStringContainsString('Reset:', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_aborts_when_confirmation_declined(): void
    {
        $this->poller->method('fetchFailed')->willReturn([$this->makeMessage()]);
        $this->poller->expects($this->never())->method('resetFailed');

        $tester = $this->tester();
        $tester->setInputs(['no']);
        $tester->execute([]);

        $this->assertStringContainsString('Aborted', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_reset_failure_returns_failure_exit_code(): void
    {
        $this->poller->method('fetchFailed')->willReturn([$this->makeMessage()]);
        $this->poller->method('resetFailed')->willThrowException(new \RuntimeException('DB error'));

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }
}
