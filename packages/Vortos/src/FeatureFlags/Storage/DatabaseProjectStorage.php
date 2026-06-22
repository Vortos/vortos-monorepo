<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\Project;

final class DatabaseProjectStorage implements ProjectStorageInterface
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

        return array_map([$this, 'hydrate'], $rows);
    }

    public function findBySlug(string $slug): ?Project
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('slug = :slug')
            ->setParameter('slug', $slug)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findById(string $id): ?Project
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(Project $project): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, name, slug, description, created_at, updated_at)
             VALUES
                 (:id, :name, :slug, :description, :created_at, :updated_at)
             ON CONFLICT (slug) DO UPDATE SET
                 name        = EXCLUDED.name,
                 description = EXCLUDED.description,
                 updated_at  = EXCLUDED.updated_at',
            [
                'id'          => $project->id,
                'name'        => $project->name,
                'slug'        => $project->slug,
                'description' => $project->description,
                'created_at'  => $project->createdAt->format('Y-m-d H:i:s'),
                'updated_at'  => $project->updatedAt->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function delete(string $slug): void
    {
        $this->connection->delete($this->table, ['slug' => $slug]);
    }

    private function hydrate(array $row): Project
    {
        return new Project(
            id:          (string) $row['id'],
            name:        (string) $row['name'],
            slug:        (string) $row['slug'],
            description: (string) ($row['description'] ?? ''),
            createdAt:   new \DateTimeImmutable($row['created_at']),
            updatedAt:   new \DateTimeImmutable($row['updated_at']),
        );
    }
}
