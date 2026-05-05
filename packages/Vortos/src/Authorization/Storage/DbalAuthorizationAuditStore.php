<?php

declare(strict_types=1);

namespace Vortos\Authorization\Storage;

use Doctrine\DBAL\Connection;
use Vortos\Authorization\Audit\AuthorizationAuditEntry;
use Vortos\Authorization\Contract\AuthorizationAuditStoreInterface;

final class DbalAuthorizationAuditStore implements AuthorizationAuditStoreInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(AuthorizationAuditEntry $entry): void
    {
        $this->connection->insert('authorization_audit_log', $entry->toDatabaseRow());
    }
}
