<?php

declare(strict_types=1);

namespace Vortos\Messaging\Outbox;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Vortos\Messaging\Contract\OutboxInterface;
use Vortos\Messaging\Outbox\Exception\OutboxWriteException;
use Vortos\Messaging\Serializer\SerializerLocator;
use Symfony\Component\Uid\UuidV7;
use Vortos\Domain\Event\DomainEventInterface;

/**
 * Writes domain events to the outbox table within the caller's active database transaction.
 * 
 * This class NEVER starts its own transaction. The caller — typically a domain service
 * wrapped by TransactionalMiddleware — owns the transaction boundary. The outbox row
 * and the domain changes commit atomically or roll back together.
 * 
 * The OutboxRelayWorker reads pending rows and produces them to the broker asynchronously.
 */
final class OutboxWriter implements OutboxInterface
{
    public function __construct(
        private Connection $connection,
        private SerializerLocator $serializerLocator,
        private string $table = 'vortos_outbox'
    ) {}

    public function store(DomainEventInterface $event, string $transportName, array $headers = []): void
    {
        $eventClass = get_class($event);
        
        try {
            $id = new UuidV7();
            $serializer = $this->serializerLocator->locate('json');

            $payload = $serializer->serialize($event);


            $finalHeaders = array_merge(
                ['event_class' => $eventClass],
                $headers
            );

            $now = new DateTimeImmutable();

            $this->connection->insert(
                $this->table,
                [
                    'id' => (string) $id,
                    'transport_name' => $transportName,
                    'event_class' => $eventClass,
                    'payload' => $payload,
                    'headers' => json_encode($finalHeaders, JSON_THROW_ON_ERROR),
                    'status' => 'pending',
                    'attempt_count' => 0,
                    'created_at' => $now->format('Y-m-d H:i:s'),
                    'published_at' => null,
                    'next_attempt_at' => null,
                    'failure_reason' => null
                ]
            );
        } catch (\Throwable $e) {

            throw OutboxWriteException::forEvent(
                $eventClass,
                $transportName,
                $e
            );
        }
    }
}
