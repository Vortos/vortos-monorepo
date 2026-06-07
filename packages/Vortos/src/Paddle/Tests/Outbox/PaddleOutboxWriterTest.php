<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Outbox;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Outbox\OutboxStatus;
use Vortos\Paddle\Outbox\PaddleOutboxWriter;
use Vortos\Persistence\Transaction\ActiveTransactionGuard;

final class PaddleOutboxWriterTest extends TestCase
{
    public function test_queue_inserts_row_when_transaction_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('insert')
                   ->with($this->isType('string'), $this->callback(static function (array $row): bool {
                       return $row['operation'] === 'subscription.update'
                           && isset($row['payload'])
                           && isset($row['idempotency_key'])
                           && $row['attempts'] === 0;
                   }));

        $writer = new PaddleOutboxWriter($connection, 'paddle_outbox');
        $writer->queue('subscription.update', ['id' => 'sub_123']);
    }

    public function test_queue_throws_when_no_transaction_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->never())->method('insert');

        $writer = new PaddleOutboxWriter($connection, 'paddle_outbox');

        $this->expectException(\Vortos\Persistence\Transaction\TransactionRequiredException::class);
        $writer->queue('subscription.update', ['id' => 'sub_123']);
    }

    public function test_queue_sets_status_to_pending_on_insert(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('insert')
                   ->with($this->isType('string'), $this->callback(static function (array $row): bool {
                       return $row['status'] === OutboxStatus::Pending->value;
                   }));

        $writer = new PaddleOutboxWriter($connection, 'paddle_outbox');
        $writer->queue('customer.create', ['email' => 'a@b.com']);
    }

    public function test_queue_serializes_payload_as_json(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->once())->method('insert')
                   ->with($this->isType('string'), $this->callback(static function (array $row): bool {
                       $payload = json_decode($row['payload'], true);
                       return $payload['id'] === 'sub_123'
                           && $payload['prorationMode'] === 'prorated_immediately';
                   }));

        $writer = new PaddleOutboxWriter($connection, 'paddle_outbox');
        $writer->queue('subscription.update', [
            'id'            => 'sub_123',
            'prorationMode' => 'prorated_immediately',
        ]);
    }
}
