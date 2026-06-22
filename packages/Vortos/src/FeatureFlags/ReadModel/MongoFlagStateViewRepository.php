<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ReadModel;

use Vortos\PersistenceMongo\Read\MongoStore;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\Attribute\MongoIndex;

/**
 * MongoDB-backed current-flag-state view. `MongoStore` for `flag_state_view` is auto-wired
 * via {@see MongoCollection}.
 */
#[MongoCollection('flag_state_view')]
#[MongoIndex(key: ['archived' => 1])]
final class MongoFlagStateViewRepository implements FlagStateViewRepositoryInterface
{
    public function __construct(private readonly MongoStore $store) {}

    public function upsert(FlagStateView $view): void
    {
        $this->store->upsert($view->flagName, $view->toDocument());
    }

    public function findByName(string $flagName): ?FlagStateView
    {
        $doc = $this->store->findById($flagName);

        return $doc !== null ? FlagStateView::fromDocument($doc) : null;
    }

    public function all(int $limit = 500): array
    {
        $docs = $this->store->findByCriteria([], ['_id' => 1], $limit);

        return array_map(static fn(array $doc) => FlagStateView::fromDocument($doc), $docs);
    }
}
