<?php

declare(strict_types=1);

namespace Vortos\Backup\Catalog;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

final class DbalBackupCatalogReadModel implements BackupCatalogReadModelInterface, RetentionCatalogInterface, WalVolumeReadModelInterface
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

    public function listRestorePoints(DatabaseEngine $engine, string $environment): array
    {
        $rows = $this->scopedSelect($engine, $environment)
            ->andWhere('kind <> :wal')
            ->setParameter('wal', BackupKind::WalSegment->value)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): BackupArtifact => BackupArtifact::fromArray($row), $rows);
    }

    public function listWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $before,
    ): array {
        $rows = $this->scopedSelect($engine, $environment)
            ->andWhere('kind = :wal')
            ->andWhere('created_at < :before')
            ->setParameter('wal', BackupKind::WalSegment->value)
            ->setParameter('before', BackupArtifact::encodeTimestamp($before))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): BackupArtifact => BackupArtifact::fromArray($row), $rows);
    }

    public function countWalFrom(
        DatabaseEngine $engine,
        string $environment,
        ?DateTimeImmutable $from,
    ): int {
        $qb = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->andWhere('kind = :wal')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->setParameter('wal', BackupKind::WalSegment->value);

        if ($from !== null) {
            $qb->andWhere('created_at >= :from')
                ->setParameter('from', BackupArtifact::encodeTimestamp($from));
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * {@inheritDoc}
     *
     * COALESCE on both aggregates: SUM over no rows is NULL, not 0, and an empty window is a normal
     * state (a freshly provisioned host, or one where archiving has legitimately not fired yet).
     * Returning null here would make "no segments" indistinguishable from "no data recorded" one
     * layer up, where the difference decides whether to alarm.
     */
    public function walVolumeSince(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $from,
    ): array {
        $row = $this->connection->createQueryBuilder()
            ->select('COUNT(*) AS segments', 'COALESCE(SUM(size_bytes), 0) AS bytes')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->andWhere('kind = :wal')
            ->andWhere('created_at >= :from')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->setParameter('wal', BackupKind::WalSegment->value)
            ->setParameter('from', BackupArtifact::encodeTimestamp($from))
            ->executeQuery()
            ->fetchAssociative();

        return [
            'segments' => (int) ($row['segments'] ?? 0),
            'bytes'    => (int) ($row['bytes'] ?? 0),
        ];
    }

    /**
     * The engine+environment slice, newest first — the ordering every caller below relies on, and
     * the column order of `idx_backup_engine_env_created`.
     */
    private function scopedSelect(DatabaseEngine $engine, string $environment): \Doctrine\DBAL\Query\QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC');
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

    /** @param non-empty-list<BackupKind> $kinds */
    public function latestOfKind(DatabaseEngine $engine, string $environment, array $kinds): ?BackupArtifact
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->andWhere('kind IN (:kinds)')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->setParameter(
                'kinds',
                array_map(static fn (BackupKind $k): string => $k->value, $kinds),
                \Doctrine\DBAL\ArrayParameterType::STRING,
            )
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? BackupArtifact::fromArray($row) : null;
    }
}
