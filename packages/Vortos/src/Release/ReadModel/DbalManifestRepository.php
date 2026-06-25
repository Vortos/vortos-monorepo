<?php

declare(strict_types=1);

namespace Vortos\Release\ReadModel;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\ManifestAlreadyExistsException;

final class DbalManifestRepository implements ManifestRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $manifestTable,
        private readonly string $envStateTable,
    ) {}

    public function record(BuildManifest $manifest): void
    {
        try {
            $this->connection->insert($this->manifestTable, [
                'build_id' => $manifest->buildId,
                'git_sha' => $manifest->gitSha,
                'image_digest' => $manifest->imageDigest,
                'target_arch' => $manifest->targetArch->value,
                'environment' => $manifest->environment,
                'schema_hash' => $manifest->schemaFingerprint->hash,
                'migration_ids' => json_encode($manifest->schemaFingerprint->migrationIds, JSON_THROW_ON_ERROR),
                'provenance' => $manifest->provenance !== null
                    ? json_encode($manifest->provenance->toArray(), JSON_THROW_ON_ERROR)
                    : null,
                'created_at' => $manifest->createdAt->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ManifestAlreadyExistsException::forBuildId($manifest->buildId);
        }

        $this->upsertEnvState($manifest);
    }

    private function upsertEnvState(BuildManifest $manifest): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->envStateTable . '
                 (environment, schema_hash, migration_ids, updated_at)
             VALUES
                 (:environment, :schema_hash, :migration_ids, :updated_at)
             ON CONFLICT (environment) DO UPDATE SET
                 schema_hash    = EXCLUDED.schema_hash,
                 migration_ids  = EXCLUDED.migration_ids,
                 updated_at     = EXCLUDED.updated_at',
            [
                'environment' => $manifest->environment,
                'schema_hash' => $manifest->schemaFingerprint->hash,
                'migration_ids' => json_encode($manifest->schemaFingerprint->migrationIds, JSON_THROW_ON_ERROR),
                'updated_at' => $manifest->createdAt->format('Y-m-d H:i:s'),
            ],
        );
    }
}
