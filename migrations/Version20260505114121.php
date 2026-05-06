<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505114121 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authorization rbac';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS role_permissions (
    role VARCHAR(150) NOT NULL,
    permission VARCHAR(190) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role, permission)
)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_role_permissions_role ON role_permissions (role)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_role_permissions_permission ON role_permissions (permission)');
        $this->addSql('CREATE TABLE IF NOT EXISTS user_roles (
    user_id VARCHAR(190) NOT NULL,
    role VARCHAR(150) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role)
)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_roles_user ON user_roles (user_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_roles_role ON user_roles (role)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'This migration was generated from a module SQL stub and has no automatic rollback.'
        );
    }
}
