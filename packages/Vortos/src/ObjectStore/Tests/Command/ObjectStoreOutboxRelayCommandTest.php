<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\ObjectStore\Command\ObjectStoreOutboxRelayCommand;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRelayInterface;

final class ObjectStoreOutboxRelayCommandTest extends TestCase
{
    public function test_command_processes_one_batch_and_exits_with_once_flag(): void
    {
        $relay = $this->createMock(ObjectStoreOutboxRelayInterface::class);
        $relay->expects($this->once())->method('relay')->willReturn(4);

        $tester = new CommandTester(new ObjectStoreOutboxRelayCommand($relay));
        $code   = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('4', $tester->getDisplay());
    }

    public function test_command_reports_zero_when_nothing_delivered(): void
    {
        $relay = $this->createMock(ObjectStoreOutboxRelayInterface::class);
        $relay->expects($this->once())->method('relay')->willReturn(0);

        $tester = new CommandTester(new ObjectStoreOutboxRelayCommand($relay));
        $code   = $tester->execute(['--once' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('0', $tester->getDisplay());
    }

    public function test_command_accepts_sleep_option(): void
    {
        $relay = $this->createMock(ObjectStoreOutboxRelayInterface::class);
        $relay->expects($this->once())->method('relay')->willReturn(0);

        $tester = new CommandTester(new ObjectStoreOutboxRelayCommand($relay));
        $code   = $tester->execute(['--once' => true, '--sleep' => '0']);

        $this->assertSame(Command::SUCCESS, $code);
    }
}
