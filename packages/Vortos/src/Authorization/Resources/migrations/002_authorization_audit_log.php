<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Authorization';
    }

    public function id(): string
    {
        return 'authorization.audit_log';
    }

    public function description(): string
    {
        return 'Authorization audit log';
    }

    public function define(Schema $schema): void
    {
        $audit = $schema->createTable('authorization_audit_log');
        $audit->addColumn('id', 'string', ['length' => 64, 'notnull' => true]);
        $audit->addColumn('actor_user_id', 'string', ['length' => 190, 'notnull' => true]);
        $audit->addColumn('action', 'string', ['length' => 190, 'notnull' => true]);
        $audit->addColumn('target_user_id', 'string', ['length' => 190, 'notnull' => false]);
        $audit->addColumn('role', 'string', ['length' => 150, 'notnull' => false]);
        $audit->addColumn('permission', 'string', ['length' => 190, 'notnull' => false]);
        $audit->addColumn('reason', 'text', ['notnull' => false]);
        $audit->addColumn('metadata', 'text', ['notnull' => true, 'default' => '{}']);
        $audit->addColumn('request_id', 'string', ['length' => 190, 'notnull' => false]);
        $audit->addColumn('correlation_id', 'string', ['length' => 190, 'notnull' => false]);
        $audit->addColumn('ip_address', 'string', ['length' => 64, 'notnull' => false]);
        $audit->addColumn('user_agent', 'text', ['notnull' => false]);
        $audit->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $audit->setPrimaryKey(['id']);
        $audit->addIndex(['actor_user_id'], 'idx_authorization_audit_actor');
        $audit->addIndex(['target_user_id'], 'idx_authorization_audit_target');
        $audit->addIndex(['action'], 'idx_authorization_audit_action');
        $audit->addIndex(['role'], 'idx_authorization_audit_role');
        $audit->addIndex(['permission'], 'idx_authorization_audit_permission');
        $audit->addIndex(['request_id'], 'idx_authorization_audit_request');
        $audit->addIndex(['correlation_id'], 'idx_authorization_audit_correlation');
        $audit->addIndex(['created_at'], 'idx_authorization_audit_created');
    }
};
