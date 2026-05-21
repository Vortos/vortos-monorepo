<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Schema;

use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\PersistenceMongo\Schema\Attribute\MongoIndex;

/**
 * Discovers MongoDB index declarations from #[MongoIndex] attributes on read repository classes.
 *
 * Repository class names are registered at compile time by MongoReadRepositoryAutowirePass
 * via addRepositoryClass(). At runtime, scan() reflects over each class and reads
 * #[MongoCollection] (for the collection name) and #[MongoIndex] (for index definitions).
 *
 * This replaces the file-based MongoIndexProviderScanner — no provider files needed.
 * Indexes are declared directly on the repository class alongside the queries that use them.
 */
final class MongoIndexAttributeScanner
{
    /** @var list<class-string> */
    private array $repositoryClasses = [];

    public function addRepositoryClass(string $class): void
    {
        $this->repositoryClasses[] = $class;
    }

    /**
     * @return list<array{class: class-string, collection: string, indexes: list<array{key: array<string, int|string>, options: array<string, mixed>}>}>
     */
    public function scan(): array
    {
        $results = [];

        foreach ($this->repositoryClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $ref = new \ReflectionClass($class);

            $collectionAttrs = $ref->getAttributes(MongoCollection::class);
            if (empty($collectionAttrs)) {
                continue;
            }

            /** @var MongoCollection $collectionAttr */
            $collectionAttr = $collectionAttrs[0]->newInstance();
            $collection     = $collectionAttr->name;

            $indexes = [];
            foreach ($ref->getAttributes(MongoIndex::class) as $attr) {
                /** @var MongoIndex $index */
                $index     = $attr->newInstance();
                $indexes[] = [
                    'key'     => $index->key,
                    'options' => $index->toOptions(),
                ];
            }

            $results[] = [
                'class'      => $class,
                'collection' => $collection,
                'indexes'    => $indexes,
            ];
        }

        usort($results, static fn(array $a, array $b) => strcmp($a['collection'], $b['collection']));

        return $results;
    }
}
