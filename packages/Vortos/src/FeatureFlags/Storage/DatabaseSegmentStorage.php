<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Segment;

final class DatabaseSegmentStorage implements SegmentStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function findAll(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByName(string $name): ?Segment
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('name = :name')
            ->setParameter('name', $name)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(Segment $segment): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, name, description, rules, project_id, created_at, updated_at)
             VALUES
                 (:id, :name, :description, :rules, :project_id, :created_at, :updated_at)
             ON CONFLICT (name) DO UPDATE SET
                 description = EXCLUDED.description,
                 rules       = EXCLUDED.rules,
                 project_id  = EXCLUDED.project_id,
                 updated_at  = EXCLUDED.updated_at',
            [
                'id'          => $segment->id,
                'name'        => $segment->name,
                'description' => $segment->description,
                'rules'       => json_encode(array_map(fn(FlagRule $r) => $r->toArray(), $segment->rules), JSON_THROW_ON_ERROR),
                'project_id'  => $segment->projectId,
                'created_at'  => $segment->createdAt->format('Y-m-d H:i:s'),
                'updated_at'  => $segment->updatedAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function delete(string $name): void
    {
        $this->connection->delete($this->table, ['name' => $name]);
    }

    private function hydrate(array $row): Segment
    {
        return new Segment(
            id:          $row['id'],
            name:        $row['name'],
            description: $row['description'],
            rules:       array_map(
                fn(array $r) => FlagRule::fromArray($r),
                json_decode($row['rules'], true, 512) ?? [],
            ),
            createdAt:   new \DateTimeImmutable($row['created_at']),
            updatedAt:   new \DateTimeImmutable($row['updated_at']),
            projectId:   $row['project_id'] ?? ProjectContext::DEFAULT_PROJECT,
        );
    }
}
