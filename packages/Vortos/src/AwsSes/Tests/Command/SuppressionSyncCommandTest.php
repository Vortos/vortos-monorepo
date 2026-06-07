<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Command;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\AwsSes\Command\SuppressionSyncCommand;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class SuppressionSyncCommandTest extends TestCase
{
    private function makeClient(MockHandler $handler): SesV2Client
    {
        return new SesV2Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
            'retries'     => 0,
        ]);
    }

    private function makeList(): SuppressionListInterface
    {
        return new class implements SuppressionListInterface {
            /** @var array<array{address: string, reason: SuppressionReason}> */
            public array $suppressed = [];

            public function isSuppressed(EmailAddress $address): bool { return false; }
            public function suppress(EmailAddress $address, SuppressionReason $reason): void
            {
                $this->suppressed[] = ['address' => $address->address(), 'reason' => $reason];
            }
            public function unsuppress(EmailAddress $address): void {}
            public function list(int $limit = 100, int $offset = 0): array { return []; }
        };
    }

    public function test_syncs_entries_from_api(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'bounce@example.com', 'Reason' => 'BOUNCE'],
                ['EmailAddress' => 'complaint@example.com', 'Reason' => 'COMPLAINT'],
            ],
            'NextToken' => null,
        ]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        $this->assertCount(2, $list->suppressed);
    }

    public function test_maps_bounce_reason_correctly(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'bounce@example.com', 'Reason' => 'BOUNCE'],
            ],
        ]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(SuppressionReason::Bounce, $list->suppressed[0]['reason']);
    }

    public function test_maps_complaint_reason_correctly(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'complaint@example.com', 'Reason' => 'COMPLAINT'],
            ],
        ]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(SuppressionReason::Complaint, $list->suppressed[0]['reason']);
    }

    public function test_dry_run_does_not_write_to_list(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'bounce@example.com', 'Reason' => 'BOUNCE'],
            ],
        ]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $this->assertCount(0, $list->suppressed);
    }

    public function test_dry_run_reports_count(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'a@example.com', 'Reason' => 'BOUNCE'],
                ['EmailAddress' => 'b@example.com', 'Reason' => 'COMPLAINT'],
            ],
        ]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('2', $tester->getDisplay());
    }

    public function test_returns_success_on_empty_list(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result(['SuppressedDestinationSummaries' => []]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_returns_failure_on_api_error(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Connection refused',
            new Command('ListSuppressedDestinations'),
            ['code' => 'NetworkingError'],
        ));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_paginates_using_next_token(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'page1@example.com', 'Reason' => 'BOUNCE'],
            ],
            'NextToken' => 'page2-token',
        ]));
        $handler->append(new Result([
            'SuppressedDestinationSummaries' => [
                ['EmailAddress' => 'page2@example.com', 'Reason' => 'BOUNCE'],
            ],
            'NextToken' => null,
        ]));

        $list    = $this->makeList();
        $command = new SuppressionSyncCommand($this->makeClient($handler), $list);
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertCount(2, $list->suppressed);
    }
}
