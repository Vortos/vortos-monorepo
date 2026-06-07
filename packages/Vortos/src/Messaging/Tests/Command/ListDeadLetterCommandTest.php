<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Messaging\Command\ListDeadLetterCommand;
use Vortos\Messaging\DeadLetter\DeadLetterRepositoryInterface;

final class ListDeadLetterCommandTest extends TestCase
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
        ], $overrides);
    }

    private function makeTester(array $rows): CommandTester
    {
        $repo = $this->createMock(DeadLetterRepositoryInterface::class);
        $repo->method('fetchFailed')->willReturn($rows);

        return new CommandTester(new ListDeadLetterCommand($repo));
    }

    public function test_shows_empty_message_when_no_rows(): void
    {
        $tester = $this->makeTester([]);
        $tester->execute([]);

        $this->assertStringContainsString('No failed messages', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_row_count(): void
    {
        $tester = $this->makeTester([$this->makeRow(), $this->makeRow(['id' => 'dlq-uuid-5678'])]);
        $tester->execute([]);

        $this->assertStringContainsString('Found 2 failed message(s)', $tester->getDisplay());
    }

    public function test_shows_handler_id(): void
    {
        $tester = $this->makeTester([$this->makeRow()]);
        $tester->execute([]);

        $this->assertStringContainsString('user.registered.send-welcome-email', $tester->getDisplay());
    }

    public function test_shows_event_short_name(): void
    {
        $tester = $this->makeTester([$this->makeRow()]);
        $tester->execute([]);

        $this->assertStringContainsString('UserRegistered', $tester->getDisplay());
    }

    public function test_handler_filter_applied_locally(): void
    {
        $rows = [
            $this->makeRow(['handler_id' => 'user.registered.email']),
            $this->makeRow(['handler_id' => 'user.registered.audit']),
        ];

        $tester = $this->makeTester($rows);
        $tester->execute(['--handler' => 'user.registered.email']);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('user.registered.email', $display);
        $this->assertStringNotContainsString('user.registered.audit', $display);
        $this->assertStringContainsString('Found 1 failed message(s)', $display);
    }

    public function test_shows_tip_line(): void
    {
        $tester = $this->makeTester([$this->makeRow()]);
        $tester->execute([]);

        $this->assertStringContainsString('vortos:dlq:show', $tester->getDisplay());
        $this->assertStringContainsString('vortos:dlq:replay', $tester->getDisplay());
    }
}
