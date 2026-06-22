<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Relational (DBAL) audit-log read model — the default backing, so the feature-flags
 * package needs no datastore beyond the relational DB the rest of Vortos already uses.
 * (A Mongo adapter exists as an optional swap-in but is never required.)
 *
 * Idempotent: upsert keyed by `event_id` (re-delivery / replay safe).
 */
final class DbalFlagAuditLogRepository implements FlagAuditLogRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function upsert(FlagAuditEntry $entry): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (event_id, flag_id, flag_name, environment, event_type, actor_id, reason, occurred_at, data)
             VALUES
                 (:event_id, :flag_id, :flag_name, :environment, :event_type, :actor_id, :reason, :occurred_at, :data)
             ON CONFLICT (event_id) DO UPDATE SET
                 flag_id     = EXCLUDED.flag_id,
                 flag_name   = EXCLUDED.flag_name,
                 environment = EXCLUDED.environment,
                 event_type  = EXCLUDED.event_type,
                 actor_id    = EXCLUDED.actor_id,
                 reason      = EXCLUDED.reason,
                 occurred_at = EXCLUDED.occurred_at,
                 data        = EXCLUDED.data',
            [
                'event_id'    => $entry->eventId,
                'flag_id'     => $entry->flagId,
                'flag_name'   => $entry->flagName,
                'environment' => $entry->environment,
                'event_type'  => $entry->eventType,
                'actor_id'    => $entry->actorId,
                'reason'      => $entry->reason,
                'occurred_at' => $entry->occurredAt,
                'data'        => json_encode($entry->data, JSON_THROW_ON_ERROR),
            ],
        );
    }

    public function findByFlag(string $flagName, int $limit = 100): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('flag_name = :flag')
            ->setParameter('flag', $flagName)
            ->orderBy('occurred_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row) => FlagAuditEntry::fromDocument([
                '_id'         => $row['event_id'],
                'flag_id'     => $row['flag_id'],
                'flag_name'   => $row['flag_name'],
                'environment' => $row['environment'] ?? 'production',
                'event_type'  => $row['event_type'],
                'actor_id'    => $row['actor_id'],
                'reason'      => $row['reason'],
                'occurred_at' => $row['occurred_at'],
                'data'        => json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR),
            ]),
            $rows,
        );
    }

    public function stream(\Vortos\FeatureFlags\Compliance\Export\AuditExportFilter $filter): \Generator
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->orderBy('occurred_at', 'ASC');

        if ($filter->flagName !== null) {
            $qb->andWhere('flag_name = :flag')->setParameter('flag', $filter->flagName);
        }
        if ($filter->environment !== null) {
            $qb->andWhere('environment = :env')->setParameter('env', $filter->environment);
        }
        if ($filter->actorId !== null) {
            $qb->andWhere('actor_id = :actor')->setParameter('actor', $filter->actorId);
        }
        if ($filter->from !== null) {
            $qb->andWhere('occurred_at >= :from')->setParameter('from', $filter->from->format(\DateTimeInterface::ATOM));
        }
        if ($filter->to !== null) {
            $qb->andWhere('occurred_at <= :to')->setParameter('to', $filter->to->format(\DateTimeInterface::ATOM));
        }

        $result = $qb->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield FlagAuditEntry::fromDocument([
                '_id'         => $row['event_id'],
                'flag_id'     => $row['flag_id'],
                'flag_name'   => $row['flag_name'],
                'environment' => $row['environment'] ?? 'production',
                'event_type'  => $row['event_type'],
                'actor_id'    => $row['actor_id'],
                'reason'      => $row['reason'],
                'occurred_at' => $row['occurred_at'],
                'data'        => json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR),
            ]);
        }
    }
}
