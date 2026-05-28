<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\AwsSes\Command\SesSuppressionListCommand;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;

final class SesSuppressionListCommandTest extends TestCase
{
    private function makeList(array $rows): SuppressionListInterface
    {
        return new class($rows) implements SuppressionListInterface {
            public function __construct(private readonly array $rows) {}
            public function isSuppressed(EmailAddress $address): bool { return false; }
            public function suppress(EmailAddress $address, SuppressionReason $reason): void {}
            public function unsuppress(EmailAddress $address): void {}
            public function list(int $limit = 100, int $offset = 0): array { return $this->rows; }
        };
    }

    public function test_displays_table_of_suppressed_addresses(): void
    {
        $rows = [
            ['email_address' => 'bounce@example.com',    'reason' => 'bounce',    'suppressed_at' => '2024-01-01 00:00:00'],
            ['email_address' => 'complaint@example.com', 'reason' => 'complaint', 'suppressed_at' => '2024-01-02 00:00:00'],
        ];

        $command = new SesSuppressionListCommand($this->makeList($rows));
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('bounce@example.com',    $output);
        $this->assertStringContainsString('complaint@example.com', $output);
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_info_when_no_entries(): void
    {
        $command = new SesSuppressionListCommand($this->makeList([]));
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('No suppressed addresses found', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_shows_entry_count(): void
    {
        $rows = [
            ['email_address' => 'a@example.com', 'reason' => 'bounce', 'suppressed_at' => '2024-01-01'],
        ];

        $command = new SesSuppressionListCommand($this->makeList($rows));
        $tester  = new CommandTester($command);
        $tester->execute([]);

        $this->assertStringContainsString('1 entries', $tester->getDisplay());
    }
}
