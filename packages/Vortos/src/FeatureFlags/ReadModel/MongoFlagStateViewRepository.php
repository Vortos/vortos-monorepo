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
        // Keyed by the compound (environment, flag_name) id — the same flag may exist
        // once per environment (Block 10). Keying by flag name alone would let one
        // environment's projection clobber another's.
        $this->store->upsert($view->compoundKey(), $view->toDocument());
    }

    public function findByName(string $flagName, string $environment = 'production'): ?FlagStateView
    {
        $doc = $this->store->findById($environment . ':' . $flagName);

        return $doc !== null ? FlagStateView::fromDocument($doc) : null;
    }

    public function all(string $environment = 'production', int $limit = 500): array
    {
        $docs = $this->store->findByCriteria(['environment' => $environment], ['_id' => 1], $limit);

        return array_map(static fn(array $doc) => FlagStateView::fromDocument($doc), $docs);
    }
}
