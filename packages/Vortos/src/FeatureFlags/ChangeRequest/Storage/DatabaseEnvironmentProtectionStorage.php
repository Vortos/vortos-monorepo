<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\ChangeRequest\EnvironmentProtection;

final class DatabaseEnvironmentProtectionStorage implements EnvironmentProtectionStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function findForEnvironment(string $environment, string $projectId = 'default'): ?EnvironmentProtection
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('environment = :env AND project_id = :project_id')
            ->setParameter('env', $environment)
            ->setParameter('project_id', $projectId)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function save(EnvironmentProtection $protection): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (environment, project_id, protected, required_approvals, require_reason, request_ttl_seconds)
             VALUES
                 (:environment, :project_id, :protected, :required_approvals, :require_reason, :request_ttl_seconds)
             ON CONFLICT (environment, project_id) DO UPDATE SET
                 protected            = EXCLUDED.protected,
                 required_approvals   = EXCLUDED.required_approvals,
                 require_reason       = EXCLUDED.require_reason,
                 request_ttl_seconds  = EXCLUDED.request_ttl_seconds',
            [
                'environment'         => $protection->environment,
                'project_id'          => $protection->projectId,
                'protected'           => $protection->protected ? 1 : 0,
                'required_approvals'  => $protection->requiredApprovals,
                'require_reason'      => $protection->requireReason ? 1 : 0,
                'request_ttl_seconds' => $protection->requestTtlSeconds,
            ],
        );
    }

    private function hydrate(array $row): EnvironmentProtection
    {
        return new EnvironmentProtection(
            environment:        (string) $row['environment'],
            projectId:          (string) $row['project_id'],
            protected:          (bool) $row['protected'],
            requiredApprovals:  (int) $row['required_approvals'],
            requireReason:      (bool) $row['require_reason'],
            requestTtlSeconds:  (int) $row['request_ttl_seconds'],
        );
    }
}
