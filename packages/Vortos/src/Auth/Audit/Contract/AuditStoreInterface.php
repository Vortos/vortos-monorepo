<?php
declare(strict_types=1);

namespace Vortos\Auth\Audit\Contract;

use Vortos\Auth\Audit\AuditEntry;

/**
 * Stores audit log entries.
 *
 * Auto-discovered — just implement this interface.
 * Recommended: PostgreSQL-backed implementation.
 *
 * Example:
 *   class PostgresAuditStore implements AuditStoreInterface
 *   {
 *       public function record(AuditEntry $entry): void
 *       {
 *           $this->connection->insert('audit_log', $entry->toArray());
 *       }
 *   }
 */
interface AuditStoreInterface
{
    public function record(AuditEntry $entry): void;
}
