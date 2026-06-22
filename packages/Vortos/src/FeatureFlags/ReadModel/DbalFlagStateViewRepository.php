<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

use Doctrine\DBAL\Connection;

/**
 * Relational (DBAL) current-flag-state view — the default backing (no second datastore).
 * Idempotent upsert keyed by `(environment, flag_name)` — unique compound key (Block 10).
 * Back-compat: rows without an explicit environment column default to 'production'.
 */
final class DbalFlagStateViewRepository implements FlagStateViewRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function upsert(FlagStateView $view): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (environment, flag_name, flag_id, enabled, archived, value_type, kind, rule_count, variants, scheduled, last_event_type, last_actor_id, updated_at)
             VALUES
                 (:environment, :flag_name, :flag_id, :enabled, :archived, :value_type, :kind, :rule_count, :variants, :scheduled, :last_event_type, :last_actor_id, :updated_at)
             ON CONFLICT (environment, flag_name) DO UPDATE SET
                 flag_id         = EXCLUDED.flag_id,
                 enabled         = EXCLUDED.enabled,
                 archived        = EXCLUDED.archived,
                 value_type      = EXCLUDED.value_type,
                 kind            = EXCLUDED.kind,
                 rule_count      = EXCLUDED.rule_count,
                 variants        = EXCLUDED.variants,
                 scheduled       = EXCLUDED.scheduled,
                 last_event_type = EXCLUDED.last_event_type,
                 last_actor_id   = EXCLUDED.last_actor_id,
                 updated_at      = EXCLUDED.updated_at',
            [
                'environment'     => $view->environment,
                'flag_name'       => $view->flagName,
                'flag_id'         => $view->flagId,
                'enabled'         => $view->enabled ? 1 : 0,
                'archived'        => $view->archived ? 1 : 0,
                'value_type'      => $view->valueType,
                'kind'            => $view->kind,
                'rule_count'      => $view->ruleCount,
                'variants'        => $view->variants !== null ? json_encode($view->variants, JSON_THROW_ON_ERROR) : null,
                'scheduled'       => $view->scheduled ? 1 : 0,
                'last_event_type' => $view->lastEventType,
                'last_actor_id'   => $view->lastActorId,
                'updated_at'      => $view->updatedAt,
            ],
        );
    }

    public function findByName(string $flagName, string $environment = 'production'): ?FlagStateView
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('flag_name = :flag AND environment = :env')
            ->setParameter('flag', $flagName)
            ->setParameter('env', $environment)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function all(string $environment = 'production', int $limit = 500): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('environment = :env')
            ->setParameter('env', $environment)
            ->orderBy('flag_name', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): FlagStateView
    {
        return FlagStateView::fromDocument([
            '_id'             => ($row['environment'] ?? 'production') . ':' . $row['flag_name'],
            'flag_name'       => $row['flag_name'],
            'flag_id'         => $row['flag_id'],
            'environment'     => $row['environment'] ?? 'production',
            'enabled'         => (bool) $row['enabled'],
            'archived'        => (bool) $row['archived'],
            'value_type'      => $row['value_type'],
            'kind'            => $row['kind'],
            'rule_count'      => (int) $row['rule_count'],
            'variants'        => $row['variants'] !== null ? json_decode((string) $row['variants'], true, 512, JSON_THROW_ON_ERROR) : null,
            'scheduled'       => (bool) $row['scheduled'],
            'last_event_type' => $row['last_event_type'],
            'last_actor_id'   => $row['last_actor_id'],
            'updated_at'      => $row['updated_at'],
        ]);
    }
}
