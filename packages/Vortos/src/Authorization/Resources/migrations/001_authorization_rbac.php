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
        return 'authorization.rbac';
    }

    public function description(): string
    {
        return 'Authorization rbac';
    }

    public function define(Schema $schema): void
    {
        $rolePermissions = $schema->createTable('role_permissions');
        $rolePermissions->addColumn('role', 'string', ['length' => 150, 'notnull' => true]);
        $rolePermissions->addColumn('permission', 'string', ['length' => 190, 'notnull' => true]);
        $rolePermissions->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $rolePermissions->setPrimaryKey(['role', 'permission']);
        $rolePermissions->addIndex(['role'], 'idx_role_permissions_role');
        $rolePermissions->addIndex(['permission'], 'idx_role_permissions_permission');

        $userRoles = $schema->createTable('user_roles');
        $userRoles->addColumn('user_id', 'string', ['length' => 190, 'notnull' => true]);
        $userRoles->addColumn('role', 'string', ['length' => 150, 'notnull' => true]);
        $userRoles->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userRoles->setPrimaryKey(['user_id', 'role']);
        $userRoles->addIndex(['user_id'], 'idx_user_roles_user');
        $userRoles->addIndex(['role'], 'idx_user_roles_role');
    }
};
