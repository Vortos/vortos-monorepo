<?php

declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Command\OutboxReplayCommand;
use Vortos\Messaging\Command\ReplayDeadLetterCommand;
use Vortos\Messaging\Command\ReplayTimestampRange;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\DeadLetter\DeadLetterRepository;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;

final class ReplayTimestampFilterTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
    }

    public function test_iso_timestamp_with_z_is_accepted(): void
    {
        $range = ReplayTimestampRange::fromOptions(
            '2026-05-11T10:00:00Z',
            '2026-05-11T10:30:00Z',
            '--failed-from',
            '--failed-to',
        );

        $this->assertSame('2026-05-11 10:00:00', ReplayTimestampRange::formatSql($range->from));
        $this->assertSame('2026-05-11 10:30:00', ReplayTimestampRange::formatSql($range->to));
    }

    public function test_timestamp_with_offset_is_accepted(): void
    {
        $range = ReplayTimestampRange::fromOptions(
            '2026-05-11T15:30:00+05:30',
            null,
            '--failed-from',
            '--failed-to',
        );

        $this->assertSame('2026-05-11 10:00:00', ReplayTimestampRange::formatSql($range->from));
    }

    public function test_date_only_value_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--failed-from must be a full timestamp');

        ReplayTimestampRange::fromOptions('2026-05-11', null, '--failed-from', '--failed-to');
    }

    public function test_impossible_calendar_timestamp_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--failed-from must be a valid timestamp');

        ReplayTimestampRange::fromOptions('2026-02-31T10:00:00Z', null, '--failed-from', '--failed-to');
    }

    public function test_reversed_created_range_is_rejected_before_fetching(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->expects($this->never())->method('fetchFailed');

        $tester = new CommandTester(new OutboxReplayCommand($poller, new NullLogger()));
        $exitCode = $tester->execute([
            '--created-from' => '2026-05-11T10:30:00Z',
            '--created-to' => '2026-05-11T10:00:00Z',
        ]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('--created-from must be earlier than or equal to --created-to', $tester->getDisplay());
    }

    public function test_outbox_replay_passes_created_range_to_poller(): void
    {
        $poller = $this->createMock(OutboxPollerInterface::class);
        $poller->expects($this->once())
            ->method('fetchFailed')
            ->with(
                25,
                'user.events',
                null,
                null,
                true,
                $this->callback(fn($date): bool => $this->isSqlTimestamp($date, '2026-05-11 10:00:00')),
                $this->callback(fn($date): bool => $this->isSqlTimestamp($date, '2026-05-11 10:30:00')),
            )
            ->willReturn([]);

        $tester = new CommandTester(new OutboxReplayCommand($poller, new NullLogger()));
        $exitCode = $tester->execute([
            '--limit' => '25',
            '--transport' => 'user.events',
            '--latest' => true,
            '--created-from' => '2026-05-11T10:00:00Z',
            '--created-to' => '2026-05-11T10:30:00Z',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_dlq_replay_rejects_date_only_value_before_fetching(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $tester = new CommandTester(new ReplayDeadLetterCommand(
            new DeadLetterRepository($connection),
            $this->producer(),
            new SerializerLocator([]),
            new HandlerRegistry([]),
            new TransportRegistry([]),
            new NullLogger(),
        ));

        $exitCode = $tester->execute(['--failed-from' => '2026-05-11']);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('--failed-from must be a full timestamp', $tester->getDisplay());
    }

    public function test_dead_letter_repository_filters_by_failed_at_range(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool =>
                    str_contains($sql, 'failed_at >= :failed_from')
                    && str_contains($sql, 'failed_at <= :failed_to')
                    && str_contains($sql, 'ORDER BY failed_at ASC')
                ),
                $this->callback(fn(array $params): bool =>
                    $params['failed_from'] === '2026-05-11 10:00:00'
                    && $params['failed_to'] === '2026-05-11 10:30:00'
                ),
                $this->anything(),
            )
            ->willReturn([]);

        $repository = new DeadLetterRepository($connection);
        $repository->fetchFailed(
            failedFrom: new \DateTimeImmutable('2026-05-11 10:00:00'),
            failedTo: new \DateTimeImmutable('2026-05-11 10:30:00'),
        );
    }

    public function test_outbox_poller_filters_by_created_at_range(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool =>
                    str_contains($sql, 'created_at >= :created_from')
                    && str_contains($sql, 'created_at <= :created_to')
                    && str_contains($sql, 'FOR UPDATE SKIP LOCKED')
                ),
                $this->callback(fn(array $params): bool =>
                    $params['created_from'] === '2026-05-11 10:00:00'
                    && $params['created_to'] === '2026-05-11 10:30:00'
                ),
                $this->anything(),
            )
            ->willReturn([]);

        $poller = new \Vortos\Messaging\Outbox\OutboxPoller($connection);
        $poller->fetchFailed(
            createdFrom: new \DateTimeImmutable('2026-05-11 10:00:00'),
            createdTo: new \DateTimeImmutable('2026-05-11 10:30:00'),
        );
    }

    private function isSqlTimestamp(mixed $date, string $expected): bool
    {
        return $date instanceof DateTimeInterface && $date->format('Y-m-d H:i:s') === $expected;
    }

    private function producer(): ProducerInterface
    {
        return new class implements ProducerInterface {
            public function produce(string $transportName, DomainEventInterface $event, array $headers = []): void {}

            public function produceBatch(string $transportName, array $events, array $headers = []): void {}
        };
    }
}
