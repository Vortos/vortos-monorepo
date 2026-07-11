<?php

declare(strict_types=1);

namespace Vortos\Audit\Query\Dbal;

use Doctrine\DBAL\Connection;
use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;
use Vortos\Audit\Query\AuditCursor;
use Vortos\Audit\Query\AuditPage;
use Vortos\Audit\Query\AuditQuery;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Storage\Dbal\StoredAuditEventRowMapper;

/**
 * Keyset-paginated reader over audit_events. Orders by (occurred_at DESC, id DESC) and
 * pages with a row-value comparison against the cursor — constant cost at any depth.
 * Fetches limit+1 rows to detect whether a next page exists.
 */
final class DbalAuditQueryReader implements AuditQueryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_audit_events',
    ) {}

    public function page(AuditQuery $query): AuditPage
    {
        [$where, $params] = $this->buildWhere($query);
        $limit = $query->boundedLimit();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table}"
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' ORDER BY occurred_at DESC, id DESC'
            . ' LIMIT :lim',
            [...$params, 'lim' => $limit + 1],
        );

        $hasMore = count($rows) > $limit;
        $rows    = array_slice($rows, 0, $limit);

        $records = array_map([StoredAuditEventRowMapper::class, 'toStored'], $rows);

        $next = null;
        if ($hasMore && $records !== []) {
            $last = $records[array_key_last($records)];
            $next = new AuditCursor($last->event->occurredAt->format('Y-m-d\TH:i:s.uP'), $last->event->id);
        }

        return new AuditPage(array_values($records), $next);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildWhere(AuditQuery $query): array
    {
        $parts  = ['scope = :scope'];
        $params = ['scope' => $query->scope->value];

        if ($query->scope === Scope::Tenant) {
            $parts[]            = 'tenant_id = :tenant_id';
            $params['tenant_id'] = $query->tenantId;
        }
        if ($query->actorId !== null) {
            // actor is JSON; match the top-level id without a JSON operator for portability.
            $parts[]           = "actor LIKE :actor_id";
            $params['actor_id'] = '%"id":"' . $query->actorId . '"%';
        }
        if ($query->action !== null) {
            $parts[]         = 'action = :action';
            $params['action'] = $query->action;
        }
        if ($query->minSensitivity !== null) {
            $levels = $this->sensitivityAtLeast($query->minSensitivity);
            $in = [];
            foreach ($levels as $i => $lvl) {
                $key = "sens{$i}";
                $in[] = ':' . $key;
                $params[$key] = $lvl;
            }
            $parts[] = 'sensitivity IN (' . implode(', ', $in) . ')';
        }
        if ($query->outcome !== null) {
            $parts[]          = 'outcome = :outcome';
            $params['outcome'] = $query->outcome->value;
        }
        if ($query->targetType !== null) {
            $parts[]              = 'target LIKE :target_type';
            $params['target_type'] = '%"type":"' . $query->targetType . '"%';
        }
        if ($query->targetId !== null) {
            $parts[]            = 'target LIKE :target_id';
            $params['target_id'] = '%"id":"' . $query->targetId . '"%';
        }
        if ($query->from !== null) {
            $parts[]       = 'occurred_at >= :from';
            $params['from'] = $query->from->format('Y-m-d\TH:i:s.uP');
        }
        if ($query->to !== null) {
            $parts[]     = 'occurred_at <= :to';
            $params['to'] = $query->to->format('Y-m-d\TH:i:s.uP');
        }
        if ($query->cursor !== null) {
            // Keyset: strictly "older" than the cursor in (occurred_at, id) order.
            $parts[] = '(occurred_at < :cur_ts OR (occurred_at = :cur_ts AND id < :cur_id))';
            $params['cur_ts'] = $query->cursor->occurredAt;
            $params['cur_id'] = $query->cursor->id;
        }

        return [implode(' AND ', $parts), $params];
    }

    /**
     * @return list<string> sensitivity values >= the given floor
     */
    private function sensitivityAtLeast(Sensitivity $floor): array
    {
        return array_values(array_filter(
            array_map(static fn (Sensitivity $s): string => $s->value, Sensitivity::cases()),
            static fn (string $v): bool => Sensitivity::from($v)->atLeast($floor),
        ));
    }
}
