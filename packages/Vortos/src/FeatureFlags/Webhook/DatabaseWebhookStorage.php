<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Webhook;

use Doctrine\DBAL\Connection;

/**
 * DBAL-backed webhook subscription storage. Persists outbound flag-event subscriptions
 * (the dispatcher reads active ones and POSTs signed payloads). Delivery records are
 * accepted but not persisted in this table-minimal implementation.
 */
final class DatabaseWebhookStorage implements WebhookStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    /** @return WebhookSubscription[] */
    public function findActive(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('active = :active')
            ->setParameter('active', true, \Doctrine\DBAL\ParameterType::BOOLEAN)
            ->orderBy('created_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(string $id): ?WebhookSubscription
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(WebhookSubscription $subscription, string $rawSecret): void
    {
        // The controller has already derived secretHash from rawSecret; persist the hash.
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, url, secret_hash, event_types, project_id, environment, active, created_at)
             VALUES
                 (:id, :url, :secret_hash, :event_types, :project_id, :environment, :active, :created_at)
             ON CONFLICT (id) DO UPDATE SET
                 url          = EXCLUDED.url,
                 secret_hash  = EXCLUDED.secret_hash,
                 event_types  = EXCLUDED.event_types,
                 project_id   = EXCLUDED.project_id,
                 environment  = EXCLUDED.environment,
                 active       = EXCLUDED.active',
            [
                'id'          => $subscription->id,
                'url'         => $subscription->url,
                'secret_hash' => $subscription->secretHash,
                'event_types' => json_encode($subscription->eventTypes, JSON_THROW_ON_ERROR),
                'project_id'  => $subscription->projectId,
                'environment' => $subscription->environment,
                'active'      => $subscription->active ? 1 : 0,
                'created_at'  => $subscription->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function delete(string $id): void
    {
        $this->connection->delete($this->table, ['id' => $id]);
    }

    public function recordDelivery(WebhookDeliveryRecord $record): void
    {
        // Delivery audit is out of scope for this table-minimal storage; no-op.
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): WebhookSubscription
    {
        return new WebhookSubscription(
            id:          $row['id'],
            url:         $row['url'],
            secretHash:  $row['secret_hash'],
            eventTypes:  json_decode((string) $row['event_types'], true, 512) ?? [],
            projectId:   $row['project_id'] ?? null,
            environment: $row['environment'] ?? null,
            active:      (bool) $row['active'],
            createdAt:   new \DateTimeImmutable($row['created_at']),
        );
    }
}
