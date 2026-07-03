<?php

declare(strict_types=1);

namespace Vortos\Release\ReadModel;

use Doctrine\DBAL\Connection;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\Provenance;
use Vortos\Release\Schema\KnownMigrationSet;
use Vortos\Release\Schema\SchemaFingerprint;

final class DbalManifestReadModel implements ManifestReadModelInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $manifestTable,
        private readonly string $envStateTable,
    ) {}

    public function manifest(string $buildId): ?BuildManifest
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->manifestTable)
            ->where('build_id = :id')
            ->setParameter('id', $buildId)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function latestForEnvironment(string $environment): ?BuildManifest
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->manifestTable)
            ->where('environment = :env')
            ->setParameter('env', $environment)
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function previousForEnvironment(string $environment): ?BuildManifest
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->manifestTable)
            ->where('environment = :env')
            ->setParameter('env', $environment)
            ->orderBy('created_at', 'DESC')
            ->setFirstResult(1)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function currentApplied(string $environment): SchemaFingerprint
    {
        $row = $this->connection->createQueryBuilder()
            ->select('migration_ids')
            ->from($this->envStateTable)
            ->where('environment = :env')
            ->setParameter('env', $environment)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return SchemaFingerprint::empty();
        }

        $ids = json_decode((string) $row['migration_ids'], true, 512, JSON_THROW_ON_ERROR);

        return new SchemaFingerprint($ids);
    }

    public function knownMigrationSet(): KnownMigrationSet
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('DISTINCT migration_ids')
            ->from($this->manifestTable)
            ->executeQuery()
            ->fetchAllAssociative();

        $allIds = [];
        foreach ($rows as $row) {
            $ids = json_decode((string) $row['migration_ids'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($ids as $id) {
                $allIds[] = $id;
            }
        }

        return new KnownMigrationSet($allIds);
    }

    public function knownMigrationSetForEnvironment(string $environment): KnownMigrationSet
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('DISTINCT migration_ids')
            ->from($this->manifestTable)
            ->where('environment = :env')
            ->setParameter('env', $environment)
            ->executeQuery()
            ->fetchAllAssociative();

        $allIds = [];
        foreach ($rows as $row) {
            $ids = json_decode((string) $row['migration_ids'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($ids as $id) {
                $allIds[] = $id;
            }
        }

        return new KnownMigrationSet($allIds);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): BuildManifest
    {
        $migrationIds = json_decode((string) $row['migration_ids'], true, 512, JSON_THROW_ON_ERROR);
        $provenance = $row['provenance'] !== null
            ? Provenance::fromArray(json_decode((string) $row['provenance'], true, 512, JSON_THROW_ON_ERROR))
            : null;

        return new BuildManifest(
            buildId: $row['build_id'],
            gitSha: $row['git_sha'],
            imageRepository: $row['image_repository'],
            imageDigest: $row['image_digest'],
            targetArch: Arch::from($row['target_arch']),
            environment: $row['environment'],
            schemaFingerprint: new SchemaFingerprint($migrationIds),
            createdAt: new \DateTimeImmutable($row['created_at']),
            provenance: $provenance,
        );
    }
}
