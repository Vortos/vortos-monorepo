<?php

declare(strict_types=1);

namespace Vortos\Tests\Authorization\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Audit\AuthorizationAuditEntry;
use Vortos\Authorization\Storage\DbalAuthorizationAuditStore;

final class DbalAuthorizationAuditStoreTest extends TestCase
{
    private const TABLE = 'authorization_audit_log';

    private \Doctrine\DBAL\Connection $connection;
    private DbalAuthorizationAuditStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (id VARCHAR(64) NOT NULL, actor_user_id VARCHAR(190) NOT NULL, action VARCHAR(190) NOT NULL, target_user_id VARCHAR(190) DEFAULT NULL, role VARCHAR(150) DEFAULT NULL, permission VARCHAR(190) DEFAULT NULL, reason TEXT DEFAULT NULL, metadata TEXT NOT NULL DEFAULT \'{}\', request_id VARCHAR(190) DEFAULT NULL, correlation_id VARCHAR(190) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, user_agent TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))',
        );
        $this->store = new DbalAuthorizationAuditStore($this->connection, self::TABLE);
    }

    public function test_uses_injected_table_name_for_inserts(): void
    {
        $customTable = 'vortos_authorization_audit_log';
        $this->connection->executeStatement(
            'CREATE TABLE ' . $customTable . ' (id VARCHAR(64) NOT NULL, actor_user_id VARCHAR(190) NOT NULL, action VARCHAR(190) NOT NULL, target_user_id VARCHAR(190) DEFAULT NULL, role VARCHAR(150) DEFAULT NULL, permission VARCHAR(190) DEFAULT NULL, reason TEXT DEFAULT NULL, metadata TEXT NOT NULL DEFAULT \'{}\', request_id VARCHAR(190) DEFAULT NULL, correlation_id VARCHAR(190) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, user_agent TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))',
        );

        $customStore = new DbalAuthorizationAuditStore($this->connection, $customTable);
        $customStore->record($this->makeEntry('user_role.assigned'));

        $this->assertCount(1, $this->connection->executeQuery('SELECT id FROM ' . $customTable)->fetchAllAssociative());
        $this->assertCount(0, $this->connection->executeQuery('SELECT id FROM ' . self::TABLE)->fetchAllAssociative());
    }

    public function test_record_persists_audit_entry(): void
    {
        $this->store->record($this->makeEntry('user_role.assigned'));

        $rows = $this->connection->executeQuery('SELECT action FROM ' . self::TABLE)->fetchAllAssociative();
        $this->assertCount(1, $rows);
        $this->assertSame('user_role.assigned', $rows[0]['action']);
    }

    public function test_multiple_records_are_all_persisted(): void
    {
        $this->store->record($this->makeEntry('user_role.assigned'));
        $this->store->record($this->makeEntry('user_role.removed'));

        $rows = $this->connection->executeQuery('SELECT action FROM ' . self::TABLE . ' ORDER BY action')->fetchAllAssociative();
        $this->assertCount(2, $rows);
        $this->assertSame('user_role.assigned', $rows[0]['action']);
        $this->assertSame('user_role.removed', $rows[1]['action']);
    }

    private function makeEntry(string $action): AuthorizationAuditEntry
    {
        return AuthorizationAuditEntry::create(
            actorUserId: 'admin-1',
            action: $action,
            targetUserId: 'user-1',
            role: 'ROLE_SUPPORT',
        );
    }
}
