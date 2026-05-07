<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagRule;

final class DatabaseFlagStorage implements FlagStorageInterface
{
    private const TABLE = 'feature_flags';

    public function __construct(private readonly Connection $connection) {}

    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByName(string $name): ?FeatureFlag
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(FeatureFlag $flag): void
    {
        $row = $this->toRow($flag);

        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . '
                 (id, name, description, enabled, rules, variants, created_at, updated_at)
             VALUES
                 (:id, :name, :description, :enabled, :rules, :variants, :created_at, :updated_at)
             ON CONFLICT (name) DO UPDATE SET
                 description = EXCLUDED.description,
                 enabled     = EXCLUDED.enabled,
                 rules       = EXCLUDED.rules,
                 variants    = EXCLUDED.variants,
                 updated_at  = EXCLUDED.updated_at',
            $row,
        );
    }

    public function delete(string $name): void
    {
        $this->connection->delete(self::TABLE, ['name' => $name]);
    }

    private function hydrate(array $row): FeatureFlag
    {
        $rules    = array_map(
            fn(array $r) => FlagRule::fromArray($r),
            json_decode($row['rules'], true) ?? [],
        );
        $variants = $row['variants'] !== null
            ? json_decode($row['variants'], true)
            : null;

        return new FeatureFlag(
            id:          $row['id'],
            name:        $row['name'],
            description: $row['description'],
            enabled:     (bool) $row['enabled'],
            rules:       $rules,
            variants:    $variants,
            createdAt:   new \DateTimeImmutable($row['created_at']),
            updatedAt:   new \DateTimeImmutable($row['updated_at']),
        );
    }

    private function toRow(FeatureFlag $flag): array
    {
        return [
            'id'          => $flag->id,
            'name'        => $flag->name,
            'description' => $flag->description,
            'enabled'     => $flag->enabled ? 1 : 0,
            'rules'       => json_encode(array_map(fn(FlagRule $r) => $r->toArray(), $flag->rules)),
            'variants'    => $flag->variants !== null ? json_encode($flag->variants) : null,
            'created_at'  => $flag->createdAt->format('Y-m-d H:i:s'),
            'updated_at'  => $flag->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
