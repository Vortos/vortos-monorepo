<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

use Vortos\PersistenceMongo\Read\MongoStore;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\Attribute\MongoIndex;

/**
 * MongoDB-backed flag audit log. The `MongoStore` for the `flag_audit_log` collection is
 * auto-wired by {@see \Vortos\PersistenceMongo\DependencyInjection\Compiler\MongoReadRepositoryAutowirePass}
 * because this class carries {@see MongoCollection}.
 */
#[MongoCollection('flag_audit_log')]
#[MongoIndex(key: ['flag_name' => 1, 'occurred_at' => -1])]
final class MongoFlagAuditLogRepository implements FlagAuditLogRepositoryInterface
{
    public function __construct(private readonly MongoStore $store) {}

    public function upsert(FlagAuditEntry $entry): void
    {
        $this->store->upsert($entry->eventId, $entry->toDocument());
    }

    public function findByFlag(string $flagName, int $limit = 100): array
    {
        $docs = $this->store->findByCriteria(
            ['flag_name' => $flagName],
            ['occurred_at' => -1],
            $limit,
        );

        return array_map(static fn(array $doc) => FlagAuditEntry::fromDocument($doc), $docs);
    }

    public function stream(\Vortos\FeatureFlags\Compliance\Export\AuditExportFilter $filter): \Generator
    {
        $criteria = [];
        if ($filter->flagName !== null) {
            $criteria['flag_name'] = $filter->flagName;
        }
        if ($filter->environment !== null) {
            $criteria['environment'] = $filter->environment;
        }
        if ($filter->actorId !== null) {
            $criteria['actor_id'] = $filter->actorId;
        }

        $docs = $this->store->findByCriteria($criteria, ['occurred_at' => 1], 10_000);

        foreach ($docs as $doc) {
            $entry = FlagAuditEntry::fromDocument($doc);
            if ($filter->matches($entry)) {
                yield $entry;
            }
        }
    }
}
