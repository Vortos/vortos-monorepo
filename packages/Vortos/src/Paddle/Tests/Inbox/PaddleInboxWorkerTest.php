<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Inbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Paddle\Inbox\InboxStatus;
use Vortos\Paddle\Inbox\PaddleInboxWorker;
use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;
use Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface;
use Vortos\Paddle\Webhook\WebhookEventFactory;

final class RecordingHandler implements PaddleWebhookHandlerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $eventType,
        private readonly bool   $throws = false,
    ) {}

    public function handles(): string
    {
        return $this->eventType;
    }

    public function handle(PaddleWebhookEvent $event): void
    {
        ++$this->calls;
        if ($this->throws) {
            throw new \RuntimeException('handler exploded');
        }
    }
}

/** Distinct class so completed-handler tracking (keyed by class) can tell them apart. */
final class SecondRecordingHandler implements PaddleWebhookHandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $eventType) {}

    public function handles(): string
    {
        return $this->eventType;
    }

    public function handle(PaddleWebhookEvent $event): void
    {
        ++$this->calls;
    }
}

final class PaddleInboxWorkerTest extends TestCase
{
    private const TABLE = 'paddle_webhook_inbox';

    /** @var array<int, array{sql: string, params: array}> */
    private array $statements = [];

    private function makeWorker(Connection $connection, array $handlers, int $maxAttempts = 5): PaddleInboxWorker
    {
        return new PaddleInboxWorker(
            $connection,
            new PaddleWebhookDispatcher($handlers),
            new WebhookEventFactory(),
            new NullLogger(),
            self::TABLE,
            batchSize: 50,
            maxAttempts: $maxAttempts,
            backoffBaseSeconds: 60,
            backoffCapSeconds: 3600,
        );
    }

    private function makeConnection(array $rows): Connection
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) {
                $this->statements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            },
        );

        return $connection;
    }

    private function makeRow(
        int     $id = 1,
        string  $eventType = 'subscription.created',
        int     $attempts = 0,
        ?string $completedHandlers = null,
    ): array {
        return [
            'id'                 => $id,
            'event_id'           => 'evt_' . $id,
            'event_type'         => $eventType,
            'payload'            => json_encode([
                'event_id'        => 'evt_' . $id,
                'notification_id' => 'ntf_' . $id,
                'event_type'      => $eventType,
                'occurred_at'     => '2024-06-01T12:00:00.000000Z',
                'data'            => ['id' => 'sub_01'],
            ]),
            'attempts'           => $attempts,
            'completed_handlers' => $completedHandlers,
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastStatementContaining(string $needle): ?array
    {
        foreach (array_reverse($this->statements) as $stmt) {
            if (str_contains($stmt['sql'], $needle) || in_array($needle, $stmt['params'], true)) {
                return $stmt;
            }
        }
        return null;
    }

    public function test_successful_row_is_marked_processed(): void
    {
        $handler = new RecordingHandler('subscription.created');
        $worker  = $this->makeWorker($this->makeConnection([$this->makeRow()]), [$handler]);

        $this->assertSame(1, $worker->process());
        $this->assertSame(1, $handler->calls);

        $update = $this->lastStatementContaining(InboxStatus::Processed->value);
        $this->assertNotNull($update);
        $this->assertSame([RecordingHandler::class], json_decode($update['params']['completed'], true));
    }

    public function test_failed_handler_schedules_retry_and_row_stays_pending(): void
    {
        $handler = new RecordingHandler('subscription.created', throws: true);
        $worker  = $this->makeWorker($this->makeConnection([$this->makeRow(attempts: 0)]), [$handler]);

        $this->assertSame(0, $worker->process());

        $retry = $this->lastStatementContaining('next_attempt_at = :next');
        $this->assertNotNull($retry);
        $this->assertSame(1, $retry['params']['attempts']);
        $this->assertStringContainsString('handler exploded', $retry['params']['error']);
        // No status change — retry keeps the row pending
        $this->assertNull($this->lastStatementContaining(InboxStatus::Dead->value));
        $this->assertNull($this->lastStatementContaining(InboxStatus::Processed->value));
    }

    public function test_attempts_exhausted_marks_row_dead(): void
    {
        $handler = new RecordingHandler('subscription.created', throws: true);
        $worker  = $this->makeWorker(
            $this->makeConnection([$this->makeRow(attempts: 4)]),
            [$handler],
            maxAttempts: 5,
        );

        $this->assertSame(0, $worker->process());

        $dead = $this->lastStatementContaining(InboxStatus::Dead->value);
        $this->assertNotNull($dead);
        $this->assertSame(5, $dead['params']['attempts']);
    }

    public function test_retry_skips_handlers_that_already_completed(): void
    {
        // Scenario: first attempt ran RecordingHandler successfully, then
        // SecondRecordingHandler failed → retry. The retry must run ONLY the
        // handler that hasn't completed yet.
        $first  = new RecordingHandler('subscription.created');
        $second = new SecondRecordingHandler('subscription.created');

        $row    = $this->makeRow(attempts: 1, completedHandlers: json_encode([RecordingHandler::class]));
        $worker = $this->makeWorker($this->makeConnection([$row]), [$first, $second]);

        $this->assertSame(1, $worker->process());
        $this->assertSame(0, $first->calls, 'Completed handler must not re-run');
        $this->assertSame(1, $second->calls);

        $update = $this->lastStatementContaining(InboxStatus::Processed->value);
        $this->assertNotNull($update);
        $this->assertSame(
            [RecordingHandler::class, SecondRecordingHandler::class],
            json_decode($update['params']['completed'], true),
        );
    }

    public function test_unparseable_payload_goes_straight_to_dead(): void
    {
        $row            = $this->makeRow();
        $row['payload'] = '{invalid';
        $handler        = new RecordingHandler('subscription.created');
        $worker         = $this->makeWorker($this->makeConnection([$row]), [$handler]);

        $this->assertSame(0, $worker->process());
        $this->assertSame(0, $handler->calls);
        $this->assertNotNull($this->lastStatementContaining(InboxStatus::Dead->value));
    }

    public function test_row_with_no_matching_handlers_is_processed(): void
    {
        $handler = new RecordingHandler('transaction.paid');
        $worker  = $this->makeWorker($this->makeConnection([$this->makeRow()]), [$handler]);

        $this->assertSame(1, $worker->process());
        $this->assertSame(0, $handler->calls);
        $this->assertNotNull($this->lastStatementContaining(InboxStatus::Processed->value));
    }
}
