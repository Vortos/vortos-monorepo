<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Paddle\Command\PaddleOutboxRetryCommand;
use Vortos\Paddle\Outbox\PaddleOutboxRetryStoreInterface;

final class PaddleOutboxRetryCommandTest extends TestCase
{
    private function makeStore(int $count = 0, array $rows = [], int $reset = 0): PaddleOutboxRetryStoreInterface
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->willReturn($count);
        $store->method('listFailed')->willReturn($rows);
        $store->method('resetFailed')->willReturn($reset);
        return $store;
    }

    public function test_dry_run_shows_count_and_exits_without_resetting(): void
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->willReturn(3);
        $store->method('listFailed')->willReturn([]);
        $store->expects($this->never())->method('resetFailed');

        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $code   = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('3', $tester->getDisplay());
    }

    public function test_dry_run_renders_table_of_failed_entries(): void
    {
        $rows = [[
            'id'         => 99,
            'operation'  => 'customer.create',
            'attempts'   => 5,
            'last_error' => 'Some API error',
            'failed_at'  => '2026-06-01 10:00:00',
            'created_at' => '2026-06-01 09:00:00',
        ]];

        $store = $this->makeStore(1, $rows);
        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('customer.create', $display);
        $this->assertStringContainsString('Some API error', $display);
    }

    public function test_success_message_when_no_failed_entries_match(): void
    {
        $store = $this->makeStore(0);
        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $code   = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('No failed', $tester->getDisplay());
    }

    public function test_retry_resets_with_force_flag_skips_confirmation(): void
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->willReturn(2);
        $store->expects($this->once())->method('resetFailed')->willReturn(2);

        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $code   = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('2', $tester->getDisplay());
    }

    public function test_retry_aborts_when_confirmation_denied(): void
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->willReturn(5);
        $store->expects($this->never())->method('resetFailed');

        $command = new PaddleOutboxRetryCommand($store);
        $app     = new Application();
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->setInputs(['no']);
        $code = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Aborted', $tester->getDisplay());
    }

    public function test_retry_filtered_by_operation(): void
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->with(null, 'customer.create')->willReturn(1);
        $store->expects($this->once())->method('resetFailed')->with(100, null, 'customer.create')->willReturn(1);

        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $tester->execute(['--operation' => 'customer.create', '--force' => true]);
    }

    public function test_retry_filtered_by_id(): void
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->with(42, null)->willReturn(1);
        $store->expects($this->once())->method('resetFailed')->with(100, 42, null)->willReturn(1);

        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $tester->execute(['--id' => '42', '--force' => true]);
    }

    public function test_retry_respects_limit_option(): void
    {
        $store = $this->createMock(PaddleOutboxRetryStoreInterface::class);
        $store->method('countFailed')->willReturn(50);
        $store->expects($this->once())->method('resetFailed')->with(10, null, null)->willReturn(10);

        $tester = new CommandTester(new PaddleOutboxRetryCommand($store));
        $tester->execute(['--limit' => '10', '--force' => true]);
    }
}
