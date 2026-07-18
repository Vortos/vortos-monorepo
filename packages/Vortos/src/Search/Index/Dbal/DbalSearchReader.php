<?php

declare(strict_types=1);

namespace Vortos\Search\Index\Dbal;

use Doctrine\DBAL\Connection;
use Vortos\Search\Index\SearchIndexDriver;
use Vortos\Search\Query\SearchHit;
use Vortos\Search\Query\SearchQuery;
use Vortos\Search\Query\SearchReaderInterface;
use Vortos\Search\Query\SearchResults;
use Vortos\Search\Query\SearchScope;

/**
 * The one place scoping lives. Composes the active {@see SearchIndexDriver}'s match/rank
 * predicate with three filters into a single ranked, bounded query:
 *
 *   tenant   — WHERE tenant_id = :tenant   (belt-and-braces with DB row-level security)
 *   member   — owner_member_id IS NULL (org-shared) OR = :me (the caller's personal rows)
 *   permission — permission IS NULL (ungated) OR permission IN (caller's grants); bypassed for superusers
 *
 * Because scoping is here and matching is in the driver, changing the driver changes relevance
 * only — never who can see what.
 */
final class DbalSearchReader implements SearchReaderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SearchIndexDriver $driver,
        private readonly string $table = 'vortos_search_documents',
    ) {
    }

    public function search(SearchQuery $query, SearchScope $scope): SearchResults
    {
        if ($query->isBlank()) {
            return SearchResults::empty();
        }

        $predicate = $this->driver->compile($query->normalisedTerms(), 'q');
        $where     = [];
        $params    = $predicate->params;

        // Tenant boundary. A superuser with no tenant reads across tenants (platform console).
        if (!($scope->superuser && $scope->tenantId === '')) {
            $where[]           = 'tenant_id = :tenant';
            $params['tenant']  = $scope->tenantId;
        }

        // Within-org visibility: personal rows only for their owner.
        if ($scope->memberId !== null) {
            $where[]        = '(owner_member_id IS NULL OR owner_member_id = :me)';
            $params['me']   = $scope->memberId;
        } else {
            $where[] = 'owner_member_id IS NULL';
        }

        // Permission gate on org-shared rows (superusers skip it).
        if (!$scope->superuser) {
            if ($scope->permissions === []) {
                $where[] = 'permission IS NULL';
            } else {
                [$inSql, $inParams] = $this->inList('perm', $scope->permissions);
                $where[]            = "(permission IS NULL OR permission IN ({$inSql}))";
                $params             = array_merge($params, $inParams);
            }
        }

        // Type filter.
        if ($query->types !== []) {
            [$inSql, $inParams] = $this->inList('type', $query->types);
            $where[]            = "doc_type IN ({$inSql})";
            $params             = array_merge($params, $inParams);
        }

        // Match predicate (empty when the query is blank — already returned above).
        if (!$predicate->matchesEverything()) {
            $where[] = $predicate->whereSql;
        }

        $rankSelect = $predicate->rankSql !== '' ? $predicate->rankSql : '0';
        $orderBy    = $predicate->rankSql !== ''
            ? 'rank DESC, updated_at DESC'
            : 'updated_at DESC';

        $sql = sprintf(
            'SELECT doc_type, entity_id, tenant_id, title, subtitle, deeplink, meta, %s AS rank FROM %s WHERE %s ORDER BY %s LIMIT %d',
            $rankSelect,
            $this->table,
            implode(' AND ', $where), // always ≥1 clause: the member-visibility filter is unconditional
            $orderBy,
            $query->limit,
        );

        $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

        $hits = array_map(
            static function (array $row): SearchHit {
                $meta = [];
                if (is_string($row['meta'] ?? null) && $row['meta'] !== '') {
                    $decoded = json_decode($row['meta'], true);
                    $meta    = is_array($decoded) ? $decoded : [];
                }

                return new SearchHit(
                    type: (string) $row['doc_type'],
                    entityId: (string) $row['entity_id'],
                    title: (string) $row['title'],
                    subtitle: (string) ($row['subtitle'] ?? ''),
                    deeplink: (string) ($row['deeplink'] ?? ''),
                    score: (float) ($row['rank'] ?? 0),
                    meta: $meta,
                    tenantId: (string) ($row['tenant_id'] ?? ''),
                );
            },
            $rows,
        );

        return new SearchResults($hits);
    }

    /**
     * Build a named-placeholder IN list, e.g. (:perm0, :perm1) + [perm0 => a, perm1 => b].
     *
     * @param list<string> $values
     *
     * @return array{string, array<string, string>}
     */
    private function inList(string $prefix, array $values): array
    {
        $placeholders = [];
        $params       = [];
        foreach ($values as $i => $value) {
            $key                = "{$prefix}{$i}";
            $placeholders[]     = ":{$key}";
            $params[$key]       = $value;
        }

        return [implode(', ', $placeholders), $params];
    }
}
