<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ShowDeadLetterCommand;
use Vortos\Messaging\DeadLetter\DeadLetterRepositoryInterface;

final class ShowDeadLetterCommandTest extends TestCase
{
    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'id'              => 'dlq-uuid-1234',
            'transport_name'  => 'user.events',
            'event_class'     => 'App\\User\\Domain\\Event\\UserRegistered',
            'handler_id'      => 'user.registered.send-welcome-email',
            'failure_reason'  => 'SMTP connection refused',
            'exception_class' => 'RuntimeException',
            'attempt_count'   => 5,
            'status'          => 'failed',
            'failed_at'       => '2026-05-22 10:00:00',
            'replayed_at'     => null,
            'headers'         => json_encode(['event_id' => 'evt-001', 'correlation_id' => 'corr-001']),
            'payload'         => '{"email":"alice@example.com"}',
        ], $overrides);
    }

    public function test_shows_not_found_message(): void
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $tester = new CommandTester(new ShowDeadLetterCommand($repo));
        $tester->execute(['id' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_shows_all_core_fields(): void
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->makeRow());

        $tester = new CommandTester(new ShowDeadLetterCommand($repo));
        $tester->execute(['id' => 'dlq-uuid-1234']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('dlq-uuid-1234', $display);
        $this->assertStringContainsString('user.events', $display);
        $this->assertStringContainsString('UserRegistered', $display);
        $this->assertStringContainsString('user.registered.send-welcome-email', $display);
        $this->assertStringContainsString('RuntimeException', $display);
        $this->assertStringContainsString('SMTP connection refused', $display);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_payload_formatted(): void
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->makeRow());

        $tester = new CommandTester(new ShowDeadLetterCommand($repo));
        $tester->execute(['id' => 'dlq-uuid-1234']);

        $this->assertStringContainsString('alice@example.com', $tester->getDisplay());
    }

    public function test_shows_decoded_headers(): void
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->makeRow());

        $tester = new CommandTester(new ShowDeadLetterCommand($repo));
        $tester->execute(['id' => 'dlq-uuid-1234']);

        $this->assertStringContainsString('correlation_id', $tester->getDisplay());
        $this->assertStringContainsString('corr-001', $tester->getDisplay());
    }

    public function test_shows_replay_at_when_replayed(): void
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->makeRow([
            'status'      => 'replayed',
            'replayed_at' => '2026-05-22 11:00:00',
        ]));

        $tester = new CommandTester(new ShowDeadLetterCommand($repo));
        $tester->execute(['id' => 'dlq-uuid-1234']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('replayed', $display);
        $this->assertStringContainsString('2026-05-22 11:00:00', $display);
    }

    public function test_shows_replay_tip(): void
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->makeRow());

        $tester = new CommandTester(new ShowDeadLetterCommand($repo));
        $tester->execute(['id' => 'dlq-uuid-1234']);

        $this->assertStringContainsString('vortos:dlq:replay', $tester->getDisplay());
    }
}
