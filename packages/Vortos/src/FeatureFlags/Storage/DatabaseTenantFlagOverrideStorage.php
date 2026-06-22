<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Doctrine\DBAL\Connection;

/**
 * DBAL-backed tenant flag overrides. Reads are always filtered by `tenant_id` — the
 * tenant id is supplied by the resolver from the trusted {@see \Vortos\Tenant\TenantContext},
 * never from client input, so one tenant can never read another's overrides.
 */
final class DatabaseTenantFlagOverrideStorage implements TenantFlagOverrideStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function findAllForTenant(string $tenantId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('flag_name', 'override_json')
            ->from($this->table)
            ->where('tenant_id = :tenant')
            ->setParameter('tenant', $tenantId)
            ->executeQuery()
            ->fetchAllAssociative();

        $overrides = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['override_json'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $overrides[(string) $row['flag_name']] = $decoded;
            }
        }

        return $overrides;
    }
}
