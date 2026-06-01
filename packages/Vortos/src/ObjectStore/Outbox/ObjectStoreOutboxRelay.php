<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Vortos\ObjectStore\Contract\DirectUploadManagerInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;

final class ObjectStoreOutboxRelay implements ObjectStoreOutboxRelayInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ObjectStoreInterface $objectStore,
        private readonly DirectUploadManagerInterface $directUploadManager,
        private readonly ObjectOperationSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly string $tableName,
        private readonly int $batchSize,
        private readonly int $maxDeliveryAttempts,
        private readonly int $backoffBaseSeconds,
        private readonly int $backoffCapSeconds,
    ) {}

    public function relay(): int
    {
        $rows = $this->fetchPendingBatch();
        $processed = 0;

        foreach ($rows as $row) {
            if ($this->processRow($row)) {
                ++$processed;
            }
        }

        return $processed;
    }

    private function fetchPendingBatch(): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->executeQuery(
            "SELECT id, payload, attempt_count
             FROM {$this->tableName}
             WHERE status = 'pending'
               AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
             ORDER BY created_at ASC
             LIMIT :limit
             FOR UPDATE SKIP LOCKED",
            ['now' => $now, 'limit' => $this->batchSize],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    private function processRow(array $row): bool
    {
        $id = (string) $row['id'];

        try {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $operation = $this->serializer->fromArray($payload);
            $context = $operation->context();

            match ($operation->name()) {
                'put' => $this->objectStore->put($context['key'], $context['body'], $context['options'] ?? null),
                'delete' => $this->objectStore->delete($context['key']),
                'copy' => $this->objectStore->copy($context['source'], $context['target'], $context['options'] ?? null),
                'move' => $this->objectStore->move($context['source'], $context['target'], $context['options'] ?? null),
                'promote' => $this->directUploadManager->promote($context['request']),
                default => throw new \RuntimeException(sprintf('Unsupported object-store outbox operation: %s', $operation->name())),
            };

            $this->markDone($id);
            return true;
        } catch (\Throwable $e) {
            $attempt = (int) $row['attempt_count'] + 1;
            $this->markFailed($id, $e, $attempt);
            $this->logger->warning('object_store.outbox.delivery_failed', [
                'outbox_id' => $id,
                'attempt' => $attempt,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function markDone(string $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->executeStatement(
            "UPDATE {$this->tableName}
             SET status = 'done', processed_at = :now, last_error = NULL
             WHERE id = :id",
            ['now' => $now, 'id' => $id],
        );
    }

    private function markFailed(string $id, \Throwable $error, int $attempt): void
    {
        if ($attempt >= $this->maxDeliveryAttempts) {
            $this->connection->executeStatement(
                "UPDATE {$this->tableName}
                 SET status = 'dead', attempt_count = :attempt, last_error = :error
                 WHERE id = :id",
                ['attempt' => $attempt, 'error' => $error->getMessage(), 'id' => $id],
            );
            return;
        }

        $backoff = min($this->backoffCapSeconds, $this->backoffBaseSeconds * (2 ** ($attempt - 1)));
        $nextAttemptAt = (new \DateTimeImmutable())->modify("+{$backoff} seconds")->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            "UPDATE {$this->tableName}
             SET status = 'pending', attempt_count = :attempt,
                 next_attempt_at = :nextAttemptAt, last_error = :error
             WHERE id = :id",
            [
                'attempt' => $attempt,
                'nextAttemptAt' => $nextAttemptAt,
                'error' => $error->getMessage(),
                'id' => $id,
            ],
        );
    }
}
