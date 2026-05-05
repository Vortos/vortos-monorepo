<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505130640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authorization audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE authorization_audit_log (
    id VARCHAR(64) NOT NULL,
    actor_user_id VARCHAR(190) NOT NULL,
    action VARCHAR(190) NOT NULL,
    target_user_id VARCHAR(190) DEFAULT NULL,
    role VARCHAR(150) DEFAULT NULL,
    permission VARCHAR(190) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    metadata TEXT NOT NULL DEFAULT \'{}\',
    request_id VARCHAR(190) DEFAULT NULL,
    correlation_id VARCHAR(190) DEFAULT NULL,
    ip_address VARCHAR(64) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
)');
        $this->addSql('CREATE INDEX idx_authorization_audit_actor ON authorization_audit_log (actor_user_id)');
        $this->addSql('CREATE INDEX idx_authorization_audit_target ON authorization_audit_log (target_user_id)');
        $this->addSql('CREATE INDEX idx_authorization_audit_action ON authorization_audit_log (action)');
        $this->addSql('CREATE INDEX idx_authorization_audit_role ON authorization_audit_log (role)');
        $this->addSql('CREATE INDEX idx_authorization_audit_permission ON authorization_audit_log (permission)');
        $this->addSql('CREATE INDEX idx_authorization_audit_request ON authorization_audit_log (request_id)');
        $this->addSql('CREATE INDEX idx_authorization_audit_correlation ON authorization_audit_log (correlation_id)');
        $this->addSql('CREATE INDEX idx_authorization_audit_created ON authorization_audit_log (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'This migration was generated from a module SQL stub and has no automatic rollback.'
        );
    }
}