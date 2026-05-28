<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;
use Vortos\ObjectStore\Contract\ObjectOutboxWriterInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;
use Vortos\ObjectStore\Contract\StandaloneObjectStoreInterface;
use Vortos\ObjectStore\Exception\OutboxWriteException;
use Vortos\Persistence\Transaction\ActiveTransactionGuard;

final class ObjectStoreOutboxWriter implements ObjectOutboxWriterInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ObjectOperationSerializer $serializer,
        private readonly string $tableName = 'object_store_outbox',
        private ?ActiveTransactionGuard $transactionGuard = null,
    ) {}

    public function queue(ObjectStoreOperation $operation, ?string $domainEventId = null): void
    {
        $now = new \DateTimeImmutable();
        $this->guard()->assertActive('Object-store transactional outbox write', StandaloneObjectStoreInterface::class, ImmediateObjectStoreInterface::class);

        try {
            $this->connection->insert($this->tableName, [
                'id' => Uuid::v7()->toRfc4122(),
                'domain_event_id' => $domainEventId,
                'operation' => $operation->name(),
                'status' => OutboxStatus::Pending->value,
                'attempt_count' => 0,
                'payload' => json_encode($this->serializer->toArray($operation), JSON_THROW_ON_ERROR),
                'last_error' => null,
                'next_attempt_at' => null,
                'created_at' => $now->format('Y-m-d H:i:s.u'),
                'processed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
        } catch (\Throwable $e) {
            throw new OutboxWriteException(sprintf('Failed to queue object-store operation: %s', $e->getMessage()), previous: $e);
        }
    }

    private function guard(): ActiveTransactionGuard
    {
        return $this->transactionGuard ??= new ActiveTransactionGuard($this->connection);
    }
}
