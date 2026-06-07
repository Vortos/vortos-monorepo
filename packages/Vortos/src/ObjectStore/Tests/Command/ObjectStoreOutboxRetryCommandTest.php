<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\ObjectStore\Command\ObjectStoreOutboxRetryCommand;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRetryStoreInterface;

final class ObjectStoreOutboxRetryCommandTest extends TestCase
{
    private function makeStore(int $count = 0, array $rows = [], int $reset = 0): ObjectStoreOutboxRetryStoreInterface
    {
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn($count);
        $store->method('listDead')->willReturn($rows);
        $store->method('resetDead')->willReturn($reset);
        return $store;
    }

    public function test_dry_run_shows_count_without_resetting(): void
    {
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn(5);
        $store->method('listDead')->willReturn([]);
        $store->expects($this->never())->method('resetDead');

        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $code   = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('5', $tester->getDisplay());
    }

    public function test_dry_run_renders_dead_entry_table(): void
    {
        $rows = [[
            'id'           => 'abc-123',
            'operation'    => 'put',
            'attempt_count' => 5,
            'last_error'   => 'S3 access denied',
            'processed_at' => '2026-06-01 10:00:00',
            'created_at'   => '2026-06-01 09:00:00',
        ]];

        $store  = $this->makeStore(1, $rows);
        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('S3 access denied', $tester->getDisplay());
        $this->assertStringContainsString('put', $tester->getDisplay());
    }

    public function test_success_message_when_no_dead_entries_match(): void
    {
        $store  = $this->makeStore(0);
        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $code   = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('No dead', $tester->getDisplay());
    }

    public function test_retry_resets_with_force_flag(): void
    {
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn(3);
        $store->expects($this->once())->method('resetDead')->willReturn(3);

        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $code   = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('3', $tester->getDisplay());
    }

    public function test_retry_aborts_when_confirmation_denied(): void
    {
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn(2);
        $store->expects($this->never())->method('resetDead');

        $command = new ObjectStoreOutboxRetryCommand($store);
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
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn(1);
        $store->expects($this->once())->method('resetDead')
              ->with(100, null, 'put', null, null)
              ->willReturn(1);

        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $tester->execute(['--operation' => 'put', '--force' => true]);
    }

    public function test_retry_filtered_by_id(): void
    {
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn(1);
        $store->expects($this->once())->method('resetDead')
              ->with(100, 'abc-uuid', null, null, null)
              ->willReturn(1);

        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $tester->execute(['--id' => 'abc-uuid', '--force' => true]);
    }

    public function test_retry_invalid_date_returns_invalid_exit_code(): void
    {
        $store  = $this->makeStore(0);
        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $code   = $tester->execute(['--created-from' => 'not-a-date']);

        $this->assertSame(Command::INVALID, $code);
    }

    public function test_retry_respects_limit_option(): void
    {
        $store = $this->createMock(ObjectStoreOutboxRetryStoreInterface::class);
        $store->method('countDead')->willReturn(20);
        $store->expects($this->once())->method('resetDead')
              ->with(5, null, null, null, null)
              ->willReturn(5);

        $tester = new CommandTester(new ObjectStoreOutboxRetryCommand($store));
        $tester->execute(['--limit' => '5', '--force' => true]);
    }
}
