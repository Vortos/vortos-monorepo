<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use Doctrine\DBAL\Connection;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

final class DbalBackupCatalogReadModel implements BackupCatalogReadModelInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function byId(string $backupId): ?BackupArtifact
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', $backupId)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? BackupArtifact::fromArray($row) : null;
    }

    public function list(DatabaseEngine $engine, string $environment, ?BackupKind $kind = null): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');

        if ($kind !== null) {
            $qb->andWhere('kind = :kind')->setParameter('kind', $kind->value);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): BackupArtifact => BackupArtifact::fromArray($row), $rows);
    }

    public function latest(DatabaseEngine $engine, string $environment): ?BackupArtifact
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? BackupArtifact::fromArray($row) : null;
    }
}
