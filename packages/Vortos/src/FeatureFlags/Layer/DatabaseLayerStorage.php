<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer;

use Doctrine\DBAL\Connection;

/**
 * DBAL-backed layer storage. Serialises LayerMember[] as JSON in the `members` column.
 */
final class DatabaseLayerStorage implements LayerStorageInterface
{
    private const TABLE = 'ff_layers';

    public function __construct(private readonly Connection $connection) {}

    public function findById(string $id): ?Layer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = ?',
            [$id],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByName(string $name): ?Layer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE name = ?',
            [$name],
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByFlagName(string $flagName): ?Layer
    {
        // JSON_CONTAINS / JSON search is DB-dependent; use PHP-side filter on a full scan
        // acceptable for small layer tables (typically < 100 rows).
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE);

        foreach ($rows as $row) {
            $layer = $this->hydrate($row);
            if ($layer->findMember($flagName) !== null) {
                return $layer;
            }
        }

        return null;
    }

    /** @return Layer[] */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM ' . self::TABLE . ' ORDER BY name');

        return array_map($this->hydrate(...), $rows);
    }

    public function save(Layer $layer): void
    {
        $members = json_encode(array_map(fn(LayerMember $m) => [
            'flag_name'   => $m->flagName,
            'slice_start' => $m->sliceStart,
            'weight'      => $m->weight,
        ], $layer->members), JSON_THROW_ON_ERROR);

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE id = ?',
            [$layer->id],
        );

        if ($exists !== false) {
            $this->connection->update(self::TABLE, [
                'name'           => $layer->name,
                'salt'           => $layer->salt,
                'holdout_weight' => $layer->holdoutWeight,
                'project_id'     => $layer->projectId,
                'members'        => $members,
            ], ['id' => $layer->id]);
        } else {
            $this->connection->insert(self::TABLE, [
                'id'             => $layer->id,
                'name'           => $layer->name,
                'salt'           => $layer->salt,
                'holdout_weight' => $layer->holdoutWeight,
                'project_id'     => $layer->projectId,
                'members'        => $members,
            ]);
        }
    }

    public function delete(string $id): void
    {
        $this->connection->delete(self::TABLE, ['id' => $id]);
    }

    private function hydrate(array $row): Layer
    {
        $rawMembers = json_decode((string) $row['members'], true, 8, JSON_THROW_ON_ERROR);

        $members = array_map(fn(array $m) => new LayerMember(
            (string) $m['flag_name'],
            (int) $m['slice_start'],
            (int) $m['weight'],
        ), $rawMembers ?? []);

        return new Layer(
            id:            (string) $row['id'],
            name:          (string) $row['name'],
            salt:          (string) $row['salt'],
            holdoutWeight: (int) $row['holdout_weight'],
            members:       $members,
            projectId:     (string) $row['project_id'],
        );
    }
}
