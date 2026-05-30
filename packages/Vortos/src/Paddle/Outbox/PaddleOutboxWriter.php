<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Vortos\Persistence\Transaction\ActiveTransactionGuard;

final class PaddleOutboxWriter implements PaddleOutboxWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private ?ActiveTransactionGuard $transactionGuard = null,
    ) {}

    public function queue(string $operation, array $payload): void
    {
        $this->guard()->assertActive('Paddle transactional outbox write', PaddleOutboxWriterInterface::class, PaddleOutboxWriterInterface::class);

        $now = new \DateTimeImmutable();

        $this->connection->insert($this->table, [
            'operation'        => $operation,
            'payload'          => json_encode($payload, JSON_THROW_ON_ERROR),
            'idempotency_key'  => Uuid::v7()->toRfc4122(),
            'attempts'         => 0,
            'last_attempted_at' => null,
            'next_attempt_at'  => $now->format('Y-m-d H:i:s'),
            'failed_at'        => null,
            'created_at'       => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function guard(): ActiveTransactionGuard
    {
        return $this->transactionGuard ??= new ActiveTransactionGuard($this->connection);
    }
}
