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

    public function iterateWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        DateTimeImmutable $before,
        int $batchSize = 1000,
    ): iterable {
        $beforeEnc = BackupArtifact::encodeTimestamp($before);

        // Keyset cursor over (created_at, id), ascending. Ascending so the segments furthest past
        // the anchor — the ones a lapsed pass most needs to shed — go first. Keyset, not OFFSET, so
        // that the caller deleting each yielded row as it goes cannot shift a not-yet-seen row into
        // a page we already walked past; a value cursor is immune to the set shrinking under it.
        $cursorTs = null;
        $cursorId = null;

        while (true) {
            $qb = $this->connection->createQueryBuilder()
                ->select('*')
                ->from($this->table)
                ->where('engine = :engine')
                ->andWhere('environment = :env')
                ->andWhere('kind = :wal')
                ->andWhere('created_at < :before')
                ->setParameter('engine', $engine->value)
                ->setParameter('env', $environment)
                ->setParameter('wal', BackupKind::WalSegment->value)
                ->setParameter('before', $beforeEnc)
                ->orderBy('created_at', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->setMaxResults($batchSize);

            if ($cursorTs !== null) {
                $qb->andWhere('(created_at > :cts OR (created_at = :cts AND id > :cid))')
                    ->setParameter('cts', $cursorTs)
                    ->setParameter('cid', $cursorId);
            }

            $rows = $qb->executeQuery()->fetchAllAssociative();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $cursorTs = $row['created_at'];
                $cursorId = $row['id'];
                yield BackupArtifact::fromArray($row);
            }

            // A short page is the last page: there cannot be more rows than the cursor's tail.
            if (count($rows) < $batchSize) {
                return;
            }
        }
    }

    public function countWalOlderThan(
        DatabaseEngine $engine,
        string $environment,
        ?DateTimeImmutable $before,
    ): int {
        if ($before === null) {
            return 0;
        }

        return (int) $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->andWhere('kind = :wal')
            ->andWhere('created_at < :before')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->setParameter('wal', BackupKind::WalSegment->value)
            ->setParameter('before', BackupArtifact::encodeTimestamp($before))
            ->executeQuery()
            ->fetchOne();
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
     * {@inheritDoc}
     *
     * Reads the name from `source_ref` rather than parsing it out of `store_key`: the object key is
     * a storage-layout detail that a key-prefix change would silently reshape, while the source ref
     * is what the archiver recorded the segment AS. {@see \Vortos\Backup\Domain\SourceRef::walLsn()}
     * writes it, so the shape is owned in one place.
     */
    public function newestWalSegmentName(DatabaseEngine $engine, string $environment): ?string
    {
        $row = $this->connection->createQueryBuilder()
            ->select('source_ref')
            ->from($this->table)
            ->where('engine = :engine')
            ->andWhere('environment = :env')
            ->andWhere('kind = :wal')
            ->setParameter('engine', $engine->value)
            ->setParameter('env', $environment)
            ->setParameter('wal', BackupKind::WalSegment->value)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (!is_string($row) || $row === '') {
            return null;
        }

        $decoded = json_decode($row, true);
        $value   = is_array($decoded) ? ($decoded['value'] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
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
