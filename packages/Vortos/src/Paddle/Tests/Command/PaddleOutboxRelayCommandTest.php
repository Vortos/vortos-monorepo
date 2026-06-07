<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Paddle\Command\PaddleOutboxRelayCommand;
use Vortos\Paddle\Outbox\PaddleOutboxRelayInterface;

final class PaddleOutboxRelayCommandTest extends TestCase
{
    public function test_command_processes_one_batch_and_exits_with_once_flag(): void
    {
        $relay = $this->createMock(PaddleOutboxRelayInterface::class);
        $relay->expects($this->once())->method('relay')->willReturn(3);

        $tester = new CommandTester(new PaddleOutboxRelayCommand($relay));
        $code   = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('3', $tester->getDisplay());
    }

    public function test_command_reports_zero_when_nothing_delivered(): void
    {
        $relay = $this->createMock(PaddleOutboxRelayInterface::class);
        $relay->expects($this->once())->method('relay')->willReturn(0);

        $tester = new CommandTester(new PaddleOutboxRelayCommand($relay));
        $code   = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('0', $tester->getDisplay());
    }

    public function test_command_exits_loop_when_once_flag_set(): void
    {
        $relay = $this->createMock(PaddleOutboxRelayInterface::class);
        $relay->expects($this->once())->method('relay')->willReturn(0);

        $tester = new CommandTester(new PaddleOutboxRelayCommand($relay, sleepSecondsWhenEmpty: 0));
        $code   = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $code);
    }
}
