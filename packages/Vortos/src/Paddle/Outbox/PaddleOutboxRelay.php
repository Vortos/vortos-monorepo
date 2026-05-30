<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Paddle\SDK\Exceptions\ApiError;
use Psr\Log\LoggerInterface;

final class PaddleOutboxRelay
{
    private const MAX_ATTEMPTS    = 5;
    private const BACKOFF_BASE_S  = 60;
    private const BACKOFF_CAP_S   = 3600;

    public function __construct(
        private readonly Connection                     $connection,
        private readonly PaddleOutboxDispatcherInterface $dispatcher,
        private readonly LoggerInterface                $logger,
        private readonly string                         $table,
        private readonly int                            $batchSize = 50,
    ) {}

    public function relay(): int
    {
        $rows      = $this->fetchPendingBatch();
        $delivered = 0;

        foreach ($rows as $row) {
            if ($this->processRow($row)) {
                ++$delivered;
            }
        }

        return $delivered;
    }

    private function fetchPendingBatch(): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeQuery(
            'SELECT id, operation, payload, attempts
             FROM ' . $this->table . '
             WHERE failed_at IS NULL
               AND next_attempt_at <= :now
             ORDER BY created_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED',
            ['now' => $now, 'limit' => $this->batchSize],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    private function processRow(array $row): bool
    {
        $id      = (int) $row['id'];
        $attempt = (int) $row['attempts'] + 1;

        try {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $this->dispatcher->dispatch($row['operation'], $payload);
            $this->markDelivered($id);
            return true;
        } catch (ApiError $e) {
            if ($e->retryAfter !== null) {
                $this->scheduleRetry($id, $attempt, $e->retryAfter, $e->getMessage());
                $this->logger->warning('paddle.outbox: rate limited, backing off', [
                    'id'          => $id,
                    'operation'   => $row['operation'],
                    'retry_after' => $e->retryAfter,
                ]);
                return false;
            }

            $this->markFailed($id, $attempt, $e->getMessage());
            $this->logger->error('paddle.outbox: permanent failure (API error)', [
                'id'        => $id,
                'operation' => $row['operation'],
                'code'      => $e->errorCode,
                'detail'    => $e->detail,
            ]);
            return false;
        } catch (\Throwable $e) {
            if ($attempt >= self::MAX_ATTEMPTS) {
                $this->markFailed($id, $attempt, $e->getMessage());
                $this->logger->error('paddle.outbox: max attempts reached', [
                    'id'        => $id,
                    'operation' => $row['operation'],
                    'error'     => $e->getMessage(),
                ]);
                return false;
            }

            $backoff = min(self::BACKOFF_CAP_S, self::BACKOFF_BASE_S * (2 ** ($attempt - 1)));
            $this->scheduleRetry($id, $attempt, $backoff, $e->getMessage());
            $this->logger->warning('paddle.outbox: transient failure, retrying', [
                'id'        => $id,
                'operation' => $row['operation'],
                'attempt'   => $attempt,
                'backoff_s' => $backoff,
            ]);
            return false;
        }
    }

    private function markDelivered(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'UPDATE ' . $this->table . ' SET last_attempted_at = :now, failed_at = NULL WHERE id = :id',
            ['now' => $now, 'id' => $id],
        );
    }

    private function scheduleRetry(int $id, int $attempt, int $delaySec, string $error): void
    {
        $now           = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $nextAttemptAt = (new \DateTimeImmutable())->modify("+{$delaySec} seconds")->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET attempts = :attempts, last_attempted_at = :now, next_attempt_at = :next
             WHERE id = :id',
            ['attempts' => $attempt, 'now' => $now, 'next' => $nextAttemptAt, 'id' => $id],
        );
    }

    private function markFailed(int $id, int $attempt, string $error): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'UPDATE ' . $this->table . '
             SET attempts = :attempts, last_attempted_at = :now, failed_at = :now
             WHERE id = :id',
            ['attempts' => $attempt, 'now' => $now, 'id' => $id],
        );
    }
}
